<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Response;
use App\Models\UploadSessions;
use App\Models\Drive;
use App\Models\Files;
use App\Models\Folders;
use App\Models\Permissions;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
class UploadSessionsCon extends Controller
{
    protected $response;

    public function __construct()
    {
        $this->response = new Response();
    }


public function startUpload(Request $request)
{
    $token = $request->input('token');
    $user = null;

    // 🔐 login เฉพาะกรณีไม่มี token
    if (!$token) {
        $user = JWTAuth::parseToken()->authenticate();
    }

    $validator = Validator::make($request->all(), [
        'original_name' => 'required|string',
        'total_size'    => 'required|integer',
        'folder_id'     => 'nullable|integer',
        'token'         => 'nullable|string',
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    $folderId = $request->folder_id ?: null;
    $createdByRole = 'owner';
    $driveId = null;
    $accessPerm = null;
    $ownerId = null;

    // ===============================
    // 📁 upload ลง folder
    // ===============================
    if ($folderId) {

        $folder = Folders::where('id', $folderId)
            ->where('is_trashed', 0)
            ->firstOrFail();

        $driveId = $folder->drive_id;
        $current = $folder;

        // 🔁 ไล่ permission ขึ้น parent
        while ($current) {

            $query = Permissions::where('target_type', 'folder')
                ->where('target_id', $current->id)
                ->where('allow_edit', 1);

            if ($token) {
                // 🔓 public link
                $query->where('shared_link_token', $token)
                      ->where('scope', 'public');
            } else {
                // 🔐 login user
                $query->where(function ($q) use ($user) {
                    $q->where('owner_id', $user->id)
                      ->orWhere('shared_with_user', $user->id)
                      ->orWhereRaw('LOWER(shared_email)=?', [strtolower($user->email)]);
                });
            }

            $perm = $query->first();

            if ($perm) {
                $accessPerm = $perm;
                $createdByRole = 'editor';
                break;
            }

            $current = $current->parent_id
                ? Folders::find($current->parent_id)
                : null;
        }

        // ✅ permission decision (จุดที่แก้หลัก)
        if ($token) {
            // public
            if (!$accessPerm) {
                return response()->json(['message' => 'No upload permission'], 403);
            }
            $ownerId = $accessPerm->owner_id;

        } else {
            // login
            if (!$user || ($folder->owner_id !== $user->id && !$accessPerm)) {
                return response()->json(['message' => 'No upload permission'], 403);
            }
            $ownerId = $user->id;
        }

    } else {
        // ===============================
        // 📁 root → login only
        // ===============================
        if (!$user) {
            return response()->json(['message' => 'Login required'], 401);
        }

        $drive = Drive::where('user_id', $user->id)->firstOrFail();
        $driveId = $drive->id;
        $ownerId = $user->id;
    }

    // ===============================
    // 🆕 create upload session
    // ===============================
    $session = UploadSessions::create([
        'user_id'         => $user?->id, // public = null
        'owner_id'        => $ownerId,   // ⭐ ตัวจริง
        'drive_id'        => $driveId,
        'folder_id'       => $folderId,
        'original_name'   => $request->original_name,
        'total_size'      => $request->total_size,
        'uploaded_size'   => 0,
        'status'          => 'uploading',
        'created_by_role' => $createdByRole,
    ]);

    return response()->json([
        'success'   => true,
        'upload_id' => $session->id
    ]);
}  
public function uploadChunk(Request $request)
{
    $user = JWTAuth::parseToken()->authenticate();

    $session = UploadSessions::where('id', $request->upload_id)
        ->where('status', 'uploading')
        ->firstOrFail();

    $tempDir = storage_path('app/tmp');
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }

    $tempFile = $tempDir . '/' . $session->id . '.part';

    $currentSize = file_exists($tempFile) ? filesize($tempFile) : 0;
    $offset = (int) $request->offset;

    // 🔥 backend = source of truth
    if ($currentSize !== $offset) {
        return response()->json([
            'status' => 'resync',
            'next_offset' => $currentSize
        ]);
    }

    $chunkFile = $request->file('chunk');

    if (!$chunkFile || $chunkFile->getSize() === 0) {
        return response()->json([
            'status' => 'ok',
            'uploaded_size' => $currentSize
        ]);
    }

    file_put_contents(
        $tempFile,
        file_get_contents($chunkFile->getRealPath()),
        FILE_APPEND
    );

    $session->uploaded_size = filesize($tempFile);
    $session->save();

    return response()->json([
        'status'        => 'ok',
        'uploaded_size' => $session->uploaded_size,
        'total_size'    => $session->total_size,
    ]);
}

public function finishUpload(Request $request)
{
    $validator = Validator::make($request->all(), [
        'upload_id' => 'required|integer',
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    $user = JWTAuth::parseToken()->authenticate();

    $session = UploadSessions::where('id', $request->upload_id)
        ->where('status', 'uploading')
        ->firstOrFail();

    if ($session->uploaded_size != $session->total_size) {
        return response()->json(['message' => 'Upload not complete'], 409);
    }

    $tempFile = storage_path('app/tmp/' . $session->id . '.part');
    if (!file_exists($tempFile)) {
        return response()->json(['message' => 'Temp file missing'], 500);
    }

    // ===============================
    // 📁 folder / owner
    // ===============================
    $folder   = null;
    $ownerId  = $user->id;
    $folderPerm = null;

    if ($session->folder_id) {
        $folder = Folders::findOrFail($session->folder_id);
        $ownerId = $folder->owner_id;

        // permission ของ folder (ใช้ inherit)
        $folderPerm = Permissions::where('target_type', 'folder')
            ->where('target_id', $folder->id)
            ->where('allow_view', 1)
            ->orderByDesc('allow_edit')
            ->first();
    }

    $drive = Drive::findOrFail($session->drive_id);
    // ===============================
// 📂 path (FIXED)
// ===============================
$usernameSlug = Str::slug($drive->user->username ?: $ownerId);
$driveSlug    = Str::slug($drive->name ?: 'my-drive');

$baseUploadDir = base_path(
    'public/uploads/' . $usernameSlug . '/' . $driveSlug
);

$finalDir = $baseUploadDir;

if ($folder && $folder->url_file) {

    $folderPath = trim($folder->url_file, '/');

    // 🛡️ กัน path ซ้อน uploads/...
    if (Str::startsWith($folderPath, 'uploads/')) {
        // ตัด uploads/{user}/{drive}/ ออก
        $folderPath = Str::after($folderPath, $driveSlug . '/');
    }

    $finalDir = $baseUploadDir . '/' . $folderPath;
}

if (!is_dir($finalDir)) {
    mkdir($finalDir, 0755, true);
}

    // ===============================
    // 📄 final file
    // ===============================
$originalName = $session->original_name;
$ext = pathinfo($originalName, PATHINFO_EXTENSION);
$nameOnly = pathinfo($originalName, PATHINFO_FILENAME);

$counter = 0;
$newFileName = $originalName;

while (file_exists($finalDir . '/' . $newFileName)) {
    $counter++;
    $newFileName = $nameOnly . " ({$counter})";
    if ($ext) {
        $newFileName .= "." . $ext;
    }
}

$finalPath = $finalDir . '/' . $newFileName;


    rename($tempFile, $finalPath);
    @unlink($tempFile);

    $relativePath = str_replace(base_path('public/'), '', $finalPath);

    // ===============================
    // 💾 save file
    // ===============================
    $file = Files::create([
        'folder_id'       => $session->folder_id,
        'drive_id'        => $drive->id,
        'owner_id'        => $ownerId,
        'created_by_role' => $session->created_by_role,
        'modified_by'     => $user->id,
        'file_name' => $newFileName,
        'file_ext'        => $ext ?: null,
        'file_path'       => $relativePath,
        'size_mb'         => round($session->total_size / 1024 / 1024, 2),
        'mime_type'       => mime_content_type($finalPath),
        'is_starred'      => false,
        'is_trashed'      => false,
    ]);

    // ===============================
    // 🔐 create permission (ทุกกรณี)
    // ===============================
    Permissions::create([
        'target_type'       => 'file',
        'target_id'         => $file->id,
        'owner_id'          => $folderPerm?->owner_id ?? $ownerId,
        'shared_with_user'  => $folderPerm?->shared_with_user,
        'shared_email'      => $folderPerm?->shared_email,
        'shared_link_token' => $folderPerm?->shared_link_token,
        'scope'             => $folderPerm?->scope ?? 'private',
        'role'              => $folderPerm?->role,
        'allow_view'        => 1,
        'allow_edit'        => $session->created_by_role === 'editor',
        'allow_download'    => 1,
    ]);

    // ===============================
    // ✅ finish session
    // ===============================
    $session->status = 'completed';
    $session->save();

    return response()->json([
        'success' => true,
        'message' => 'Upload completed',
        'file_id' => $file->id,
    ]);
}


public function cancelUpload(Request $request)
{
    $validator = Validator::make($request->all(), [
        'upload_id' => 'required|integer',
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    $session = UploadSessions::find($request->upload_id);

    if (! $session) {
        return response()->json(['message' => 'Upload session not found'], 404);
    }

    if ($session->status === 'completed') {
        return response()->json([
            'message' => 'Upload already completed'
        ], 409);
    }

    if ($session->status === 'cancelled') {
        return response()->json([
            'message' => 'Upload already cancelled'
        ], 409);
    }

    if ($session->status !== 'uploading') {
        return response()->json([
            'message' => 'Upload cannot be cancelled'
        ], 409);
    }

    // 🔒 cancel
    $session->status = 'cancelled';
    $session->save();

    // 🧹 ลบไฟล์ tmp
    $tempFile = storage_path('app/tmp/' . $session->id . '.part');
    if (File::exists($tempFile)) {
        File::delete($tempFile);
    }

    return response()->json([
        'success'   => true,
        'message'   => 'Upload cancelled',
        'upload_id' => $session->id,
    ]);
}




public function startPublicUpload(Request $request, $token)
{
    $perm = Permissions::where('shared_link_token', $token)
        ->where('scope', 'public')
        ->where('allow_edit', 1)
        ->first();

    if (!$perm) {
        return response()->json([
            'success' => false,
            'message' => 'ลิงก์นี้ไม่อนุญาตให้อัพโหลด'
        ], 403);
    }

    $validator = Validator::make($request->all(), [
        'original_name' => 'required|string',
        'total_size'    => 'required|integer',
        'folder_id'     => 'nullable|integer',
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    // 📁 public upload ได้เฉพาะ folder ที่แชร์
if ($perm->target_type === 'folder') {
    if ($request->folder_id) {

        $current = Folders::findOrFail($request->folder_id);
        $allowedRootId = $perm->target_id;

        $isAllowed = false;

        // 🔁 ไล่ parent ขึ้นไปเรื่อย ๆ
        while ($current) {
            if ($current->id == $allowedRootId) {
                $isAllowed = true;
                break;
            }
            $current = $current->parent_id
                ? Folders::find($current->parent_id)
                : null;
        }

        if (! $isAllowed) {
            return response()->json([
                'message' => 'ไม่สามารถอัพโหลดนอกโฟลเดอร์ที่แชร์'
            ], 403);
        }
    }
}


    // 🔎 หา drive + folder
if ($perm->target_type === 'folder') {

    // 🔥 ถ้ามี folder_id จาก frontend → ใช้อันนั้น
    if ($request->folder_id) {
        $folder = Folders::findOrFail($request->folder_id);
    } else {
        // fallback = root ที่แชร์
        $folder = Folders::findOrFail($perm->target_id);
    }

    $driveId  = $folder->drive_id;
    $folderId = $folder->id;
}


    // 📤 create upload session (🔥 FIX จุดหลัก)
    $session = UploadSessions::create([
        'user_id'         => $perm->owner_id, // ✅ ใช้ owner เท่านั้น
        'drive_id'        => $driveId,
        'folder_id'       => $folderId,
        'original_name'   => $request->original_name,
        'total_size'      => $request->total_size,
        'uploaded_size'   => 0,
        'status'          => 'uploading',
        'created_by_role' => 'public',
    ]);

    return response()->json([
        'success'   => true,
        'upload_id' => $session->id
    ]);
}


public function uploadPublicChunk(Request $request, $uploadId)
{
    $session = UploadSessions::findOrFail($uploadId);

    $tmpDir = storage_path('app/tmp');
    if (!File::exists($tmpDir)) {
        File::makeDirectory($tmpDir, 0755, true);
    }

    $tmpPath = $tmpDir . '/' . $uploadId . '.part';

    if (!$request->hasFile('file')) {
        return response()->json(['message' => 'No file uploaded'], 400);
    }

    // 🔥 สร้างไฟล์ถ้ายังไม่มี
    if (!File::exists($tmpPath)) {
        File::put($tmpPath, '');
    }

    $chunk = $request->file('file');

    // ต่อไฟล์ (append)
    file_put_contents(
        $tmpPath,
        file_get_contents($chunk->getRealPath()),
        FILE_APPEND
    );

    $currentSize = filesize($tmpPath);

    UploadSessions::where('id', $uploadId)->update([
        'uploaded_size' => $currentSize
    ]);

    return response()->json([
        'success' => true,
        'uploaded_size' => $currentSize
    ]);
}


public function finishPublicUpload(Request $request, $uploadId)
{
    // 1️⃣ หา upload session
    $session = UploadSessions::where('id', $uploadId)
        ->where('status', 'uploading')
        ->firstOrFail();

    // 2️⃣ เช็กว่าอัปโหลดครบไหม
    if ($session->uploaded_size != $session->total_size) {
        return response()->json([
            'message' => 'Upload not complete'
        ], 409);
    }

    // 3️⃣ หา permission (public + edit)
// 🔐 หา permission จาก folder หรือ parent (inherit)
$perm = null;

$currentFolder = Folders::find($session->folder_id);

while ($currentFolder) {
    $perm = Permissions::where('scope', 'public')
        ->where('allow_edit', 1)
        ->where('target_type', 'folder')
        ->where('target_id', $currentFolder->id)
        ->first();

    if ($perm) {
        break;
    }

    // ขึ้น parent
    $currentFolder = $currentFolder->parent_id
        ? Folders::find($currentFolder->parent_id)
        : null;
}

if (! $perm) {
    return response()->json([
        'message' => 'Invalid permission'
    ], 403);
}


    // 4️⃣ โหลด folder + drive
// 🔥 ใช้ folder จริงที่อัปโหลด
$folder = Folders::findOrFail($session->folder_id);
$drive  = Drive::findOrFail($folder->drive_id);


    // 5️⃣ path ไฟล์ชั่วคราว
    $tempFile = storage_path('app/tmp/' . $session->id . '.part');

    if (!file_exists($tempFile)) {
        return response()->json([
            'message' => 'Temp file not found'
        ], 500);
    }

    // 6️⃣ path ปลายทาง
    $finalDir = base_path('public/' . $folder->url_file);
    if (!is_dir($finalDir)) {
        mkdir($finalDir, 0755, true);
    }

    // =============================
// 🔥 FIX: ป้องกันชื่อไฟล์ซ้ำ
// =============================
$originalName = $session->original_name;
$ext = pathinfo($originalName, PATHINFO_EXTENSION);
$nameOnly = pathinfo($originalName, PATHINFO_FILENAME);

$counter = 0;
$newFileName = $originalName;

while (file_exists($finalDir . '/' . $newFileName)) {
    $counter++;
    $newFileName = $nameOnly . " ({$counter})";
    if ($ext) {
        $newFileName .= "." . $ext;
    }
}

$finalPath = $finalDir . '/' . $newFileName;


    rename($tempFile, $finalPath);

    $relativePath = str_replace(base_path('public/'), '', $finalPath);

    // 🔥 7️⃣ ดึงนามสกุลไฟล์ (แก้ bug file_ext)
    $fileExt = strtolower(pathinfo($session->original_name, PATHINFO_EXTENSION));

    // 8️⃣ สร้าง record file
$file = Files::create([
    'folder_id'       => $folder->id,
    'drive_id'        => $drive->id,
    'owner_id'        => $folder->owner_id,
    'created_by_role' => 'public',
    'modified_by'     => $folder->owner_id, // 🔥 แก้ตรงนี้
    'file_name' => $newFileName,
    'file_ext'        => $fileExt,
    'file_path'       => $relativePath,
    'size_mb'         => round($session->total_size / 1024 / 1024, 2),
    'mime_type'       => mime_content_type($finalPath),
    'is_trashed'      => false,
]);


    // 9️⃣ clone permission ให้ไฟล์
    Permissions::create([
        'target_type'       => 'file',
        'target_id'         => $file->id,
        'owner_id'          => $perm->owner_id,
        'shared_link_token' => $perm->shared_link_token,
        'scope'             => 'public',
        'role'              => 'editor',
        'allow_view'        => true,
        'allow_edit'        => true,
        'allow_download'    => true,
    ]);

    // 🔟 ปิด session
    $session->status = 'completed';
    $session->save();

    return response()->json([
        'success' => true,
        'file_id' => $file->id
    ]);
}





}