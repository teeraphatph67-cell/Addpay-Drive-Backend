<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Response;
use App\Models\Files;
use App\Models\Permissions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\File as FileFacade;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Models\Drive;
use App\Models\Folders;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class FileCon extends Controller
{
    protected $response;

    public function __construct()
    {
        $this->response = new Response();
    }

public function UploadFile(Request $request)
{
    try {
        // 1. auth user
        $authUser = JWTAuth::parseToken()->authenticate();

        // 2. หา drive ของ user
        $drive = Drive::where('user_id', $authUser->id)->firstOrFail();

        // 3. validate input
        $this->validate($request, [
            'file'      => 'required|file|max:151200', // 50MB (ปรับได้)
            'folder_id' => 'nullable|integer',
        ]);

        $folderIdInput = $request->input('folder_id');

// แปลงให้เป็น null ถ้าไม่ได้ส่ง / ส่งมาเป็น "" / "0"
if ($folderIdInput === null || $folderIdInput === '' || $folderIdInput === '0') {
    $folderId = null;   // root
} else {
    $folderId = (int) $folderIdInput;
} // null = root
        $uploadedFile = $request->file('file');

        // 4. หา base path จาก folder_id หรือ root
        $publicPath = base_path('public');

        if ($folderId) {
            // อยู่ในโฟลเดอร์
            $parentFolder = Folders::where('id', $folderId)
                ->where('drive_id', $drive->id)
                ->where('owner_id', $authUser->id)
                ->firstOrFail();

            // ตัวอย่าง: uploads/wau2501/my-drive/test
            $baseRelativePath = $parentFolder->url_file;

        } else {
            // root ของ drive
            $usernameSlug = Str::slug($authUser->username ?: $authUser->id);
            $driveSlug    = Str::slug($drive->name ?: 'drive-'.$drive->id);
            // ตัวอย่าง: uploads/wau2501/my-drive
            $baseRelativePath = 'uploads/' . $usernameSlug . '/' . $driveSlug;
        }

        // โฟลเดอร์จริงบนดิสก์
        $dirPath = $publicPath . DIRECTORY_SEPARATOR .
            str_replace('/', DIRECTORY_SEPARATOR, $baseRelativePath);

        if (!FileFacade::exists($dirPath)) {
            FileFacade::makeDirectory($dirPath, 0755, true);
        }

        // 5. ชื่อไฟล์
        $originalName = $uploadedFile->getClientOriginalName();   // report.pdf
        $extension    = $uploadedFile->getClientOriginalExtension(); // pdf
        $baseName     = pathinfo($originalName, PATHINFO_FILENAME);  // report
        $safeBaseName = Str::slug($baseName);

        // ป้องกันชื่อชนกันด้วย time()
        $storedFileName = $safeBaseName . '-' . time() . '.' . $extension;

        // ย้ายไฟล์ไปที่ path จริง
        $uploadedFile->move($dirPath, $storedFileName);

        // path ที่จะเก็บลง DB (relative)
        $fileRelativePath = rtrim($baseRelativePath, '/') . '/' . $storedFileName;

        // 6. ขนาดไฟล์
        $fullFilePath = $dirPath . DIRECTORY_SEPARATOR . $storedFileName;
        $sizeBytes    = FileFacade::size($fullFilePath);
        $sizeMb       = round($sizeBytes / 1024 / 1024, 2);

        $mimeType     = $uploadedFile->getClientMimeType();

        // 7. บันทึกลง DB (ตาราง files ตามโครงนาย)
        $fileModel = Files::create([
            'folder_id'   => $folderId,          // null = root
            'drive_id'    => $drive->id,
            'owner_id'    => $authUser->id,
            'file_name'   => $originalName,      // โชว์ให้ user
            'file_path'   => $fileRelativePath,  // ใช้ต่อกับ BASE_URL
            'mime_type'   => $mimeType,
            'file_ext'    => $extension,
            'size_mb'     => $sizeMb,
            'is_starred'  => false,
            'is_trashed'  => false,
            'modified_by' => $authUser->id,
            'last_opened_at' => null,
            'last_opened_by' => null,
        ]);

        return $this->response->success($fileModel, 'อัปโหลดไฟล์สำเร็จ', 201);

    } catch (\Exception $e) {
        return $this->response->error('เกิดข้อผิดพลาด: ' . $e->getMessage(), 500);
    }
}

public function moveToTrash($id)
    {
        $file = Files::findOrFail($id);

        $file->is_trashed = 1;
        $file->save();

        return response()->json([
            'success' => true,
            'message' => 'ย้ายไฟล์ไปถังขยะเรียบร้อยแล้ว',
        ]);
    }

public function destroyForever($id)
{
    DB::transaction(function () use ($id, &$fileName) {

        $file = Files::where('id', $id)
            ->where('is_trashed', 1)
            ->firstOrFail();

        $fileName = $file->file_name;

        $fullPath = base_path('public/' . $file->file_path);

        if (File::exists($fullPath)) {
            File::delete($fullPath);
        }

        // 🔥 ลบ permissions ของไฟล์
        Permissions::where('target_type', 'file')
            ->where('target_id', $file->id)
            ->delete();

        // ลบไฟล์
        $file->delete();
    });

    return response()->json([
        'success'   => true,
        'message'   => "ลบไฟล์ '{$fileName}' ถาวรเรียบร้อยแล้ว",
        'file_id'   => $id,
        'file_name' => $fileName,
    ]);
}


public function restore($id)
{
    $file = Files::where('id', $id)
                 ->where('is_trashed', 1)
                 ->firstOrFail();

    $file->is_trashed = 0;
    $file->save();

    return response()->json([
        'success'   => true,
        'message'   => "กู้คืนไฟล์ '{$file->file_name}' เรียบร้อยแล้ว",
        'file_id'   => $file->id,
        'file_name' => $file->file_name,
    ]);
}



private function findPermissionForUserOnFile($file, $user)
{
    // เจ้าของไฟล์ → มีสิทธิ์เต็ม
    if ($file->owner_id === $user->id) {
        return (object)[
            'allow_view'     => true,
            'allow_edit'     => true,
            'allow_download' => true,
        ];
    }

    // หา permission private (แชร์ราย user)
    $perm = Permissions::where('target_type', 'file')
                       ->where('target_id', $file->id)
                       ->where('scope', 'private')
                       ->where('shared_with_user', $user->id)
                       ->first();

    return $perm;
}

public function download($id)
{
    $user = JWTAuth::parseToken()->authenticate();
    $file = Files::findOrFail($id);

    $perm = $this->findPermissionForUserOnFile($file, $user);

    if (!$perm || !$perm->allow_download) {
        return response()->json([
            'success' => false,
            'message' => 'คุณไม่มีสิทธิ์ดาวน์โหลดไฟล์นี้',
        ], 403);
    }

    // logic โหลดไฟล์จริง ตามที่มีอยู่แล้ว
}

public function update(Request $request, $id)
{
    $user = JWTAuth::parseToken()->authenticate();
    $file = Files::findOrFail($id);

    $perm = $this->findPermissionForUserOnFile($file, $user);

    if (!$perm || !$perm->allow_edit) {
        return response()->json([
            'success' => false,
            'message' => 'คุณไม่มีสิทธิ์แก้ไขไฟล์นี้',
        ], 403);
    }

    // logic แก้ไขไฟล์...
}

private function getAuthUserOrFail()
{
    try {
        return JWTAuth::parseToken()->authenticate();
    } catch (\Exception $e) {
        return null;
    }
}


public function rename(Request $request, $id)
{
    // 1) เช็ก login
    $user = $this->getAuthUserOrFail();
    if (!$user) {
        return response()->json([
            'success' => false,
            'message' => 'กรุณาเข้าสู่ระบบก่อน',
        ], 401);
    }

    // 2) validate
    $this->validate($request, [
        'file_name' => 'required|string|max:255',
    ]);

    // 3) หาไฟล์
    $file = Files::findOrFail($id);

    // 4) เช็ก permission
    $perm = $this->findPermissionForUserOnFile($file, $user);

    if (!$perm || !$perm->allow_edit) {
        return response()->json([
            'success' => false,
            'message' => 'คุณไม่มีสิทธิ์แก้ไขไฟล์นี้',
        ], 403);
    }

    // 5) rename ไฟล์จริงบนดิสก์
    $oldPath = base_path('public/' . $file->file_path);

    $ext = pathinfo($file->file_name, PATHINFO_EXTENSION);

    // กันกรณี user ใส่มาไม่มีนามสกุล
    $newName = $request->input('file_name');

    if (!Str::endsWith($newName, '.' . $ext)) {
        $newName .= '.' . $ext;
    }

    $newPath = dirname($oldPath) . DIRECTORY_SEPARATOR . $newName;

    if (!file_exists($oldPath)) {
        return response()->json([
            'success' => false,
            'message' => 'ไม่พบไฟล์ต้นฉบับในระบบ',
        ], 404);
    }

    rename($oldPath, $newPath);

    // 6) update DB
    $file->file_name = $newName;
    $file->file_path = dirname($file->file_path) . '/' . $newName;
    $file->save();

    return response()->json([
        'success' => true,
        'message' => 'เปลี่ยนชื่อไฟล์เรียบร้อยแล้ว',
        'data' => [
            'id'         => $file->id,
            'file_name' => $file->file_name,
            'file_path' => $file->file_path,
        ],
    ]);
}


private function checkAccessByPermission($type, $targetId, $action)
{
    // ถ้มี token ให้ try authenticate
    try {
        $user = JWTAuth::parseToken()->authenticate();
    } catch (\Exception $e) {
        $user = null;
    }

    // 1) owner shortcut
    if ($user) {
        if ($type === 'file') {
            $item = Files::find($targetId);
        } else {
            $item = Folders::find($targetId);
        }
        if ($item && $item->owner_id == $user->id) return true;
    }

    // 2) public
    $publicPerm = Permissions::where('target_type', $type)
                     ->where('target_id', $targetId)
                     ->where('scope', 'public')
                     ->first();
    if ($publicPerm) {
        if ($action === 'view' && !$publicPerm->allow_view) return false;
        if ($action === 'edit' && !$publicPerm->allow_edit) return false;
        if ($action === 'download' && !$publicPerm->allow_download) return false;
        return true;
    }

    // 3) private shares
    // first, if logged in, check shared_with_user match or shared_email match
    if ($user) {
        $perm = Permissions::where('target_type', $type)
                ->where('target_id', $targetId)
                ->where('scope', 'private')
                ->where(function($q) use ($user) {
                    $q->where('shared_with_user', $user->id)
                      ->orWhere('shared_email', $user->email);
                })->first();

        if (!$perm) return false;

        // check action permission flags
        if ($action === 'view' && !$perm->allow_view) return false;
        if ($action === 'edit' && !$perm->allow_edit) return false;
        if ($action === 'download' && !$perm->allow_download) return false;

        return true;
    }

    // 4) not logged in: is there a private permission that references an email? 
    $permByEmail = Permissions::where('target_type', $type)
                    ->where('target_id', $targetId)
                    ->where('scope', 'private')
                    ->whereNotNull('shared_email')
                    ->first();

    if ($permByEmail) {
        // มีการแชร์ให้ email แต่ผู้ขอดูยังไม่ล็อกอิน → บอก client ว่า "ต้องล็อกอิน" (401)
        return 'need_login';
    }

    // no permission
    return false;
}

public function show($id)
{
    $file = Files::findOrFail($id);

    // Use auth() which is set by auth middleware
    $user = auth()->user(); // returns null if not authenticated

    // owner shortcut
    if ($user && $file->owner_id == $user->id) {
        return response()->json(['success'=>true,'data'=>$file], 200);
    }

    // 1) check explicit public permission
    $publicPerm = Permissions::where('target_type','file')
                 ->where('target_id',$id)
                 ->where('scope','public')
                 ->first();
    if ($publicPerm) {
        if (!$publicPerm->allow_view) {
            return response()->json(['success'=>false,'message'=>'ลิงก์นี้ไม่อนุญาตให้ดู'], 403);
        }
        return response()->json(['success'=>true,'data'=>$file], 200);
    }

    // 2) private => if not logged in => inform to login (check shared_email first)
    if (!$user) {
        $permByEmail = Permissions::where('target_type','file')
                          ->where('target_id',$id)
                          ->where('scope','private')
                          ->whereNotNull('shared_email')
                          ->first();
        if ($permByEmail) {
            return response()->json([
                'success'=>false,
                'message'=>'ไฟล์นี้แชร์ให้ ' . $permByEmail->shared_email . ' — กรุณาเข้าสู่ระบบด้วยอีเมลนั้น'
            ], 401);
        }
        return response()->json(['success'=>false,'message'=>'กรุณาเข้าสู่ระบบเพื่อเข้าถึงไฟล์นี้'], 401);
    }

    // 3) logged-in: normalize email and check private permission
    $userEmail = strtolower($user->email);

    $perm = Permissions::where('target_type','file')
            ->where('target_id',$id)
            ->where('scope','private')
            ->where(function($q) use ($user, $userEmail) {
                $q->where('shared_with_user', $user->id)
                  ->orWhereRaw('LOWER(shared_email) = ?', [$userEmail]);
            })->first();

    if (!$perm) {
        return response()->json(['success'=>false,'message'=>'คุณไม่มีสิทธิ์ดูไฟล์นี้'], 403);
    }

    if (!$perm->allow_view) {
        return response()->json(['success'=>false,'message'=>'สิทธิ์การดูถูกปิดอยู่'], 403);
    }

    return response()->json(['success'=>true,'data'=>$file], 200);
}

public function destroyForeverAdmin($id)
{
    $fileName = null;

    DB::transaction(function () use ($id, &$fileName) {

        // 🔥 Admin: หาไฟล์ตรง ๆ ไม่สน owner
        $file = Files::findOrFail($id);

        $fileName = $file->file_name ?? $file->name ?? 'unknown';

        $fullPath = base_path('public/' . ltrim($file->file_path, '/'));

        // 🧹 ลบไฟล์จริง
        if (File::exists($fullPath)) {
            File::delete($fullPath);
        }

        // 🔥 ลบ permissions ที่ผูกกับไฟล์
        Permissions::where('target_type', 'file')
            ->where('target_id', $file->id)
            ->delete();

        // 🧨 ลบ record ไฟล์
        $file->delete();
    });

    return response()->json([
        'success'   => true,
        'message'   => "แอดมินลบไฟล์ '{$fileName}' ถาวรเรียบร้อยแล้ว",
        'file_id'   => $id,
        'file_name' => $fileName,
    ]);
}




}


