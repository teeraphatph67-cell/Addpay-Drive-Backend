<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Response;
use App\Models\Folders;
use App\Models\Drive;
use App\Models\Files;
use Carbon\Carbon;
use App\Models\Permissions;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

use ZipArchive;

class FolderCon extends Controller
{
    protected $response;

    public function __construct()
    {
        $this->response = new Response();
    }

public function AddFolder(Request $request)
{
    try {
        $authUser = JWTAuth::parseToken()->authenticate();

        $this->validate($request, [
            'name'      => 'required|string',
            'parent_id' => 'nullable|integer',
        ]);

        $parentId = $request->input('parent_id');
        $createdByRole = 'owner';
        $ownerId = $authUser->id;
        $accessPerm = null;

        // ------------------------------
        // 1. กรณีมี parent folder
        // ------------------------------
        if ($parentId) {

            $parentFolder = Folders::where('id', $parentId)
                ->where('is_trashed', 0)
                ->firstOrFail();

            // owner → ผ่าน
            if ($parentFolder->owner_id !== $authUser->id) {

                // 🔁 ไล่ permission แบบ inherit
                $current = $parentFolder;
                $perm = null;

                while ($current) {
                    $perm = Permissions::where('target_type', 'folder')
                        ->where('target_id', $current->id)
                        ->where('allow_edit', 1)
                        ->where(function ($q) use ($authUser) {
                            $q->where('shared_with_user', $authUser->id)
                              ->orWhereRaw('LOWER(shared_email) = ?', [strtolower($authUser->email)]);
                        })
                        ->first();

                    if ($perm) {
                        break;
                    }

                    $current = $current->parent_id
                        ? Folders::find($current->parent_id)
                        : null;
                }

                if (! $perm) {
                    return $this->response->error('ไม่มีสิทธิ์สร้างโฟลเดอร์', 403);
                }

                $createdByRole = 'editor';
                $ownerId = $perm->owner_id;
                $accessPerm = $perm;
            }

            $parentRelativePath = $parentFolder->url_file;

        } else {

            // ------------------------------
            // root → owner เท่านั้น
            // ------------------------------
            $drive = Drive::where('user_id', $authUser->id)->first();

            if (! $drive) {
                return $this->response->error('ไม่มีสิทธิ์สร้างที่ root', 403);
            }

            $createdByRole = 'owner';
            $ownerId = $authUser->id;

            $parentRelativePath = 'uploads/' .
                Str::slug($authUser->username ?: $authUser->id) . '/' .
                Str::slug($drive->name ?: 'drive-' . $drive->id);

            $parentFolder = (object)[
                'drive_id' => $drive->id
            ];
        }

        // ------------------------------
        // 2. สร้าง path
        // ------------------------------
        $publicPath = base_path('public');
        $folderSlug = trim($request->input('name'));

        $relativePath = rtrim($parentRelativePath, '/') . '/' . $folderSlug;
        $fullPath = $publicPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        if (!File::exists($fullPath)) {
            File::makeDirectory($fullPath, 0755, true);
        }

        // ------------------------------
        // 3. save DB
        // ------------------------------
        $folder = Folders::create([
            'drive_id'        => $parentFolder->drive_id,
            'parent_id'       => $parentId,
            'name'            => $folderSlug,
            'owner_id'        => $ownerId,
            'created_by_role' => $createdByRole,
            'url_file'        => $relativePath,
            'is_starred'      => false,
            'is_trashed'      => false,
        ]);

        // ------------------------------
        // 4. 🔥 clone permission จาก parent
        // ------------------------------
        if ($accessPerm) {
            Permissions::create([
                'target_type'       => 'folder',
                'target_id'         => $folder->id,
                'owner_id'          => $accessPerm->owner_id,
                'shared_with_user'  => $accessPerm->shared_with_user,
                'shared_email'      => $accessPerm->shared_email,
                'shared_link_token' => $accessPerm->shared_link_token,
                'scope'             => $accessPerm->scope,
                'role'              => $accessPerm->role,
                'allow_view'        => $accessPerm->allow_view,
                'allow_edit'        => $accessPerm->allow_edit,
                'allow_download'    => $accessPerm->allow_download,
            ]);
        }

        return $this->response->success($folder, 'เพิ่มโฟลเดอร์สำเร็จ', 201);

    } catch (\Exception $e) {
        return $this->response->error($e->getMessage(), 500);
    }
}

private function resolveEditPermission(Folders $folder, $user)
{
    while ($folder) {

        // owner ผ่าน
        if ($folder->owner_id === $user->id) {
            return [
                'role' => 'owner',
                'owner_id' => $folder->owner_id,
                'permission' => null
            ];
        }

        $perm = Permissions::where('target_type', 'folder')
            ->where('target_id', $folder->id)
            ->where('allow_edit', 1)
            ->where(function ($q) use ($user) {
                $q->where('shared_with_user', $user->id)
                  ->orWhereRaw('LOWER(shared_email) = ?', [strtolower($user->email)]);
            })
            ->first();

        if ($perm) {
            return [
                'role' => 'editor',
                'owner_id' => $perm->owner_id,
                'permission' => $perm
            ];
        }

        $folder = $folder->parent_id
            ? Folders::find($folder->parent_id)
            : null;
    }

    return null;
}


public function AddFolderByToken(Request $request, $token)
{
    $this->validate($request, [
        'name'      => 'required|string',
        'parent_id' => 'required|integer',
    ]);

    $perm = Permissions::where('shared_link_token', $token)
        ->where('allow_edit', 1)
        ->first();

    if (! $perm) {
        return response()->json([
            'success' => false,
            'message' => 'ไม่มีสิทธิ์สร้างโฟลเดอร์'
        ], 403);
    }

    if ($perm->expires_at && now()->gt($perm->expires_at)) {
        return response()->json([
            'success' => false,
            'message' => 'ลิงก์หมดอายุ'
        ], 410);
    }

    return $this->createFolderInternal(
        name: $request->name,
        parentId: $request->parent_id,
        actor: null,
        basePerm: $perm // 🌍 public
    );
}

private function createFolderInternal(
    string $name,
    int|null $parentId,
    $actor,
    ?Permissions $basePerm
) {
    // ------------------------------
    // หา parent folder
    // ------------------------------
    $parentFolder = Folders::where('id', $parentId)
        ->where('is_trashed', 0)
        ->firstOrFail();

    // ------------------------------
    // resolve permission
    // ------------------------------
    if ($actor) {
        // 🔐 private (login)
        if ($parentFolder->owner_id !== $actor->id) {
            $perm = $this->resolveEditPermission($parentFolder, $actor);
            if (! $perm) {
                return response()->json([
                    'success' => false,
                    'message' => 'ไม่มีสิทธิ์สร้างโฟลเดอร์'
                ], 403);
            }

            $ownerId        = $perm['owner_id'];
            $createdByRole  = $perm['role'];
            $accessPerm     = $perm['permission'];
        } else {
            $ownerId        = $actor->id;
            $createdByRole  = 'owner';
            $accessPerm     = null;
        }
    } else {
        // 🌍 public
        if (! $basePerm || ! $basePerm->allow_edit) {
            return response()->json([
                'success' => false,
                'message' => 'ลิงก์นี้ไม่อนุญาตให้แก้ไข'
            ], 403);
        }

        // 🔐 ตรวจว่า parent อยู่ใต้ folder ที่แชร์จริง
        if ($basePerm->target_type === 'folder') {
            $allowedRootId = $basePerm->target_id;
            $current = $parentFolder;
            $isAllowed = false;

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
                    'success' => false,
                    'message' => 'ไม่สามารถสร้างโฟลเดอร์นอกโฟลเดอร์ที่แชร์'
                ], 403);
            }
        }

        $ownerId        = $basePerm->owner_id;
        $createdByRole  = 'editor';
        $accessPerm     = $basePerm;
    }

    // ------------------------------
    // 🔥 กันชื่อโฟลเดอร์ซ้ำ (A, A (1), A (2))
    // ------------------------------
    $baseName = trim($name);
    $newName  = $baseName;
    $counter  = 0;

    do {
        $relativePath = rtrim($parentFolder->url_file, '/') . '/' . $newName;
        $fullPath = base_path('public/' . $relativePath);

        if (! File::exists($fullPath)) {
            break;
        }

        $counter++;
        $newName = $baseName . " ({$counter})";

    } while (true);

    $name = $newName;

    // ------------------------------
    // create directory
    // ------------------------------
    if (! File::exists($fullPath)) {
        File::makeDirectory($fullPath, 0755, true);
    }

    // ------------------------------
    // save folder
    // ------------------------------
    $folder = Folders::create([
        'drive_id'        => $parentFolder->drive_id,
        'parent_id'       => $parentId,
        'name'            => $name,
        'owner_id'        => $ownerId,
        'created_by_role' => $createdByRole,
        'url_file'        => $relativePath,
        'is_trashed'      => false,
    ]);

    // ------------------------------
    // clone permission (inherit)
    // ------------------------------
    if ($accessPerm) {
        Permissions::create([
            'target_type'       => 'folder',
            'target_id'         => $folder->id,
            'owner_id'          => $accessPerm->owner_id,
            'shared_with_user'  => $accessPerm->shared_with_user,
            'shared_email'      => $accessPerm->shared_email,
            'shared_link_token' => $accessPerm->shared_link_token,
            'scope'             => $accessPerm->scope,
            'role'              => $accessPerm->role,
            'allow_view'        => $accessPerm->allow_view,
            'allow_edit'        => $accessPerm->allow_edit,
            'allow_download'    => $accessPerm->allow_download,
        ]);
    }

    return response()->json([
        'success' => true,
        'data' => $folder
    ], 201);
}




     public function moveToTrash($id)
    {
        $folder = Folders::findOrFail($id);

        $this->trashRecursive($folder);

        return response()->json([
            'success' => true,
            'message' => 'ย้ายโฟลเดอร์ไปถังขยะเรียบร้อย',
        ]);
    }

    private function trashRecursive(Folders $folder)
    {
        $folder->load(['ManyFile', 'children']);

        // ลบไฟล์ในโฟลเดอร์นี้ → เข้าถังขยะ
        foreach ($folder->ManyFile as $file) {
            $file->is_trashed = 1;
            $file->save();
        }

        // ลบโฟลเดอร์ลูก
        foreach ($folder->children as $child) {
            $this->trashRecursive($child);
        }

        // mark โฟลเดอร์ตัวเอง
        $folder->is_trashed = 1;
        $folder->save();
    }


public function destroy($id)
{
    $user   = JWTAuth::parseToken()->authenticate();
    $folder = Folders::findOrFail($id);

    $perm = $this->findPermissionForUserOnFolder($folder, $user);

    if (!$perm || !$perm->allow_edit) {
        return response()->json([
            'success' => false,
            'message' => 'คุณไม่มีสิทธิ์ลบโฟลเดอร์นี้',
        ], 403);
    }

    DB::transaction(function () use ($folder) {

        $this->deleteFolderRecursive($folder);

        // 🔥 ลบ permissions ของโฟลเดอร์หลัก
        Permissions::where('target_type', 'folder')
            ->where('target_id', $folder->id)
            ->delete();

        $folder->delete();
    });

    return response()->json([
        'success' => true,
        'message' => 'ลบโฟลเดอร์และไฟล์ทั้งหมดเรียบร้อยแล้ว',
    ]);
}
private function deleteFolderRecursive(Folders $folder)
{
    $folder->load(['ManyFile', 'children']);

    // 1) ลบไฟล์ในโฟลเดอร์นี้
    foreach ($folder->ManyFile as $file) {

        $fullPath = base_path('public/' . $file->file_path);

        if (File::exists($fullPath)) {
            File::delete($fullPath);
        }

        // 🔥 ลบ permissions ของไฟล์
        Permissions::where('target_type', 'file')
            ->where('target_id', $file->id)
            ->delete();

        $file->delete();
    }

    // 2) ลบโฟลเดอร์ลูก (recursive)
    foreach ($folder->children as $childFolder) {

        $this->deleteFolderRecursive($childFolder);

        // 🔥 ลบ permissions ของโฟลเดอร์ลูก
        Permissions::where('target_type', 'folder')
            ->where('target_id', $childFolder->id)
            ->delete();

        $childFolder->delete();
    }

    // 3) ลบ directory บน disk
    $folderPath = base_path('public/' . $folder->url_file);

    if (File::isDirectory($folderPath)) {
        File::deleteDirectory($folderPath);
    }
}


public function restore($id)
{
    $folder = Folders::where('id', $id)
                     ->where('is_trashed', 1)
                     ->firstOrFail();

    $this->restoreRecursive($folder);

    return response()->json([
        'success'   => true,
        'message'  => "กู้คืนโฟลเดอร์ '{$folder->name}' และข้อมูลภายในเรียบร้อยแล้ว",
        'folder_id'=> $folder->id,
        'folder'   => $folder->name,
    ]);
}
private function restoreRecursive(Folders $folder)
{
    $folder->load(['ManyFile', 'children']);

    // ✅ restore โฟลเดอร์ตัวเอง
    $folder->is_trashed = 0;
    $folder->save();

    // ✅ restore ไฟล์ในโฟลเดอร์นี้
    foreach ($folder->ManyFile as $file) {
        $file->is_trashed = 0;
        $file->save();
    }

    // ✅ restore โฟลเดอร์ลูกทั้งหมด
    foreach ($folder->children as $childFolder) {
        $this->restoreRecursive($childFolder);
    }
}

private function findPermissionForUserOnFolder(Folders $folder, $user)
{
    // 1) ถ้าเป็นเจ้าของโฟลเดอร์ → สิทธิ์เต็ม
    if ($folder->owner_id === $user->id) {
        return (object)[
            'allow_view'     => true,
            'allow_edit'     => true,
            'allow_download' => true, // สำหรับกรณีโหลดทั้งโฟลเดอร์ (ถ้ามีในอนาคต)
        ];
    }

    // 2) หา permission ที่แชร์ให้ user คนนี้ (private share)
    $perm = Permissions::where('target_type', 'folder')
        ->where('target_id', $folder->id)
        ->where('scope', 'private')
        ->where('shared_with_user', $user->id)
        ->first();

    return $perm; // อาจจะเป็น null ได้
}

public function show($id)
{
    // 1) ยืนยัน user (จับ exception เพื่อไม่ให้ throw 500)
    try {
        $user = JWTAuth::parseToken()->authenticate();
    } catch (\Exception $e) {
        $user = null;
    }

    // 2) โหลดโฟลเดอร์ root พร้อม tree แบบ recursive และไฟล์ที่ active
    // ใช้ relations ที่นายมี: filesActive และ childrenRecursiveActive
    $folder = Folders::with([
        'DriveOne',
        // fallback: ถ้า relation ชื่ออื่น ให้แก้เป็นชื่อจริงของนาย เช่น 'ManyFile' หรือ 'filesActive'
        'filesActive',
        'childrenRecursiveActive'
    ])->findOrFail($id);

    // 3) เช็ก permission (ใช้ฟังก์ชันที่นายมี)
    $perm = $this->findPermissionForUserOnFolder($folder, $user);

    if (!$perm || !$perm->allow_view) {
        return response()->json([
            'success' => false,
            'message' => 'คุณไม่มีสิทธิ์เข้าถึงโฟลเดอร์นี้',
        ], 403);
    }

    // 4) แปลงโครงเป็นรูปแบบ desired (snake_case keys) แบบ recursive
    $data = $this->transformFolderForResponse($folder);

    return response()->json([
        'success' => true,
        'data' => $data,
    ], 200);
}

/**
 * แปลง Folder model -> array ตามรูปแบบที่ต้องการ (recursive)
 * ต้องการ relations:
 *  - filesActive (collection)  -> maps to files_active
 *  - childrenRecursiveActive (collection) -> maps to children_recursive_active
 *  - DriveOne (optional)
 */
private function transformFolderForResponse(Folders $folder)
{
    // base folder fields (เลือกฟิลด์ที่ต้องการ)
    $node = [
        'id'         => $folder->id,
        'drive_id'   => $folder->drive_id,
        'parent_id'  => $folder->parent_id,
        'name'       => $folder->name,
        'owner_id'   => $folder->owner_id,
        'is_starred' => (int) $folder->is_starred,
        'is_trashed' => (int) $folder->is_trashed,
        'url_file'   => $folder->url_file,
        'created_at' => isset($folder->created_at) ? $folder->created_at->toIso8601String() : null,
        'updated_at' => isset($folder->updated_at) ? $folder->updated_at->toIso8601String() : null,
    ];

    // files_active -> map fields
    $files = [];
    if ($folder->relationLoaded('filesActive') && $folder->filesActive) {
        foreach ($folder->filesActive as $f) {
            $files[] = [
                'id' => $f->id,
                'folder_id' => $f->folder_id,
                'drive_id' => $f->drive_id,
                'owner_id' => $f->owner_id,
                'file_name' => $f->file_name,
                'file_path' => $f->file_path,
                'mime_type' => $f->mime_type,
                'file_ext' => $f->file_ext,
                'size_mb' => $f->size_mb,
                'is_starred' => (int) $f->is_starred,
                'is_trashed' => (int) $f->is_trashed,
                'created_at' => isset($f->created_at) ? $f->created_at->toIso8601String() : null,
                'updated_at' => isset($f->updated_at) ? $f->updated_at->toIso8601String() : null,
                'modified_by' => $f->modified_by,
                'last_opened_at' => isset($f->last_opened_at) ? $f->last_opened_at->toIso8601String() : null,
                'last_opened_by' => $f->last_opened_by,
            ];
        }
    }
    $node['files_active'] = $files;

    // children_recursive_active -> recursive map
    $childrenArr = [];
    if ($folder->relationLoaded('childrenRecursiveActive') && $folder->childrenRecursiveActive) {
        foreach ($folder->childrenRecursiveActive as $child) {
            // each child is a Folder model that also should have filesActive and childrenRecursiveActive loaded by relation
            $childrenArr[] = $this->transformFolderForResponse($child);
        }
    }
    $node['children_recursive_active'] = $childrenArr;

    // optionally include DriveOne info under a parent structure (if you want to return drive at root level,
    // you might move DriveOne outside; here we attach it if present)
    if ($folder->relationLoaded('DriveOne') && $folder->DriveOne) {
        $node['drive'] = [
            'id' => $folder->DriveOne->id,
            'user_id' => $folder->DriveOne->user_id,
            'name' => $folder->DriveOne->name,
            'created_at' => isset($folder->DriveOne->created_at) ? $folder->DriveOne->created_at->toIso8601String() : null,
            'updated_at' => isset($folder->DriveOne->updated_at) ? $folder->DriveOne->updated_at->toIso8601String() : null,
        ];
    }

    return $node;
}


public function rename(Request $request, $id)
{
    $user   = JWTAuth::parseToken()->authenticate();
    $folder = Folders::findOrFail($id);

    $perm = $this->findPermissionForUserOnFolder($folder, $user);

    if (!$perm || !$perm->allow_edit) {
        return response()->json([
            'success' => false,
            'message' => 'คุณไม่มีสิทธิ์แก้ไขโฟลเดอร์นี้',
        ], 403);
    }

      $this->validate($request, [
        'name' => 'required|string|max:255',
    ]);

    $newName = $request->input('name');

    // 3) path เดิมบนดิสก์ (เช่น public/uploads/user/my-drive/เก่า)
    $oldRelative = $folder->url_file;                      // เช่น uploads/user/my-drive/เก่า
    $oldFull     = base_path('public/' . $oldRelative);    // path เต็ม

    // 4) สร้าง path ใหม่
    $parentRelative = dirname($oldRelative);               // uploads/user/my-drive
    if ($parentRelative === '.' || $parentRelative === '/') {
        $newRelative = $newName;
    } else {
        $newRelative = rtrim($parentRelative, '/') . '/' . $newName;
    }
    $newFull = base_path('public/' . $newRelative);

    // 5) ย้ายโฟลเดอร์บนดิสก์
    if (File::isDirectory($oldFull)) {
        File::move($oldFull, $newFull);
    } else {
        return response()->json([
            'success' => false,
            'message' => 'ไม่พบโฟลเดอร์จริงบนดิสก์',
        ], 404);
    }

    // 6) อัปเดตใน DB
    $folder->name    = $newName;
    $folder->url_file = $newRelative;  // สำคัญ! ให้ตรงกับโฟลเดอร์ใหม่
    $folder->save();

    return response()->json([
        'success' => true,
        'message' => 'เปลี่ยนชื่อโฟลเดอร์เรียบร้อยแล้ว',
        'data'    => $folder,
    ]);
}


  public function GetTrash()
        {
            try {
                $GetTrash = Files::with(['OneFolder'])->get();
                return $this->response->success($GetTrash, 'แสดงข้อมูลผู้ใช้งานทั้งหมด', 200);
            } catch (\Exception $e) {
                return $this->response->error('เกิดข้อผิดพลาด: ' . $e->getMessage(), 500);
            }
        }

public function uploadFolder(Request $request)
{
    /* =====================
       1. validate
    ====================== */
    $validator = Validator::make($request->all(), [
        'drive_id' => 'required|integer',
        'files'    => 'required|array',
        'files.*'  => 'file',
        'paths'    => 'required|array',
        'paths.*'  => 'string',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors'  => $validator->errors(),
        ], 422);
    }

    /* =====================
       2. auth + drive
    ====================== */
    $user = auth()->user();

    $drive = Drive::where('id', $request->drive_id)
        ->where('user_id', $user->id)
        ->first();

    if (!$drive) {
        return response()->json([
            'success' => false,
            'message' => 'ไม่พบ drive',
        ], 404);
    }

    /* =====================
       3. base upload path (LUMEN)
    ====================== */
    $publicRoot = base_path('public');
    $basePath = $publicRoot . "/uploads/{$user->username}/my-drive";

    if (!is_dir($basePath)) {
        mkdir($basePath, 0755, true);
    }

    DB::beginTransaction();

    try {
        // =======================
        // ตรวจสอบไฟล์ tmp ก่อน move
        // =======================
        if (!$request->hasFile('files')) {
            return response()->json([
                'success' => false,
                'message' => 'ไม่ได้รับไฟล์ใด ๆ ($_FILES ว่าง)'
            ], 422);
        }

        foreach ($request->file('files') as $i => $file) {

            if (!$file->isValid()) {
                return response()->json([
                    'success' => false,
                    'message' => "ไฟล์ {$file->getClientOriginalName()} ไม่ถูกต้อง / tmp ไม่อยู่"
                ], 422);
            }

            $tmpPath = $file->getPathname();
            if (!file_exists($tmpPath)) {
                return response()->json([
                    'success' => false,
                    'message' => "tmp file {$tmpPath} ไม่มีอยู่หรืออ่านไม่ได้"
                ], 422);
            }

            /* =====================
               4. clean path
            ====================== */
            $relativePath = str_replace(
                ['..', '\\'],
                '',
                $request->paths[$i]
            );

            $segments = array_values(array_filter(explode('/', $relativePath)));
            $fileName = array_pop($segments);

            /* =====================
               5. folder tree (DB)
            ====================== */
            $parentId = null;

            foreach ($segments as $folderName) {
                $folder = Folders::firstOrCreate(
                    [
                        'drive_id'  => $drive->id,
                        'parent_id' => $parentId,
                        'name'      => $folderName,
                    ],
                    [
                        'owner_id' => $user->id,
                    ]
                );

                $parentId = $folder->id;
            }

            /* =====================
               6. filesystem
            ====================== */
            $targetDir = $basePath;

            if (!empty($segments)) {
                $targetDir .= '/' . implode('/', $segments);
            }

            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            $safeName = uniqid() . '_' .
                preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);

            $file->move($targetDir, $safeName);

            /* =====================
               7. save file (DB)
            ====================== */
            Files::create([
                'drive_id'  => $drive->id,
                'folder_id' => $parentId,
                'owner_id'  => $user->id,
                'file_name' => $fileName,
                'file_path' => str_replace(
                    $publicRoot,
                    '',
                    $targetDir . '/' . $safeName
                ),
                'mime_type' => $file->getMimeType(),
                'size_mb'   => round($file->getSize() / 1024 / 1024, 2),
            ]);
        }

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'อัปโหลดโฟลเดอร์สำเร็จ',
        ]);

    } catch (\Throwable $e) {

        DB::rollBack();

        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], 500);
    }
}

public function downloadSingle($id)
{
    $user = JWTAuth::parseToken()->authenticate();
    $file = Files::findOrFail($id);

    // 🔒 owner check
    if ($file->owner_id !== $user->id) {
        abort(403, 'You do not own this file');
    }

    // ✅ Lumen ใช้ base_path แทน public_path
    $fullPath = base_path('public/' . ltrim($file->file_path, '/'));

    if (!file_exists($fullPath)) {
        abort(404, 'File not found');
    }

    return response()->download(
        $fullPath,
        $file->name ?? basename($fullPath),
        [
            'Content-Type' => mime_content_type($fullPath),
        ]
    );
}

public function downloadFolder($id)
{
    $user = JWTAuth::parseToken()->authenticate();

    $folder = Folders::where('id', $id)
        ->where('owner_id', $user->id)
        ->firstOrFail();

    $zipName = $folder->name . '.zip';
    $zipPath = storage_path('app/tmp/' . uniqid() . '.zip');

    File::ensureDirectoryExists(dirname($zipPath));

    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    $this->addFolderToZip($zip, $folder->id, $user);

    $zip->close();

    return response()
        ->download($zipPath, $zipName)
        ->deleteFileAfterSend(true);
}
  

public function downloadMultiple(Request $request)
{
    $user  = JWTAuth::parseToken()->authenticate();
    $items = $request->input('items');

    if (!is_array($items) || empty($items)) {
        return response()->json(['message' => 'Invalid request'], 400);
    }

    $zipName = 'download_' . time() . '.zip';
    $zipDir  = storage_path('app/tmp');
    $zipPath = $zipDir . DIRECTORY_SEPARATOR . $zipName;

    if (!File::exists($zipDir)) {
        File::makeDirectory($zipDir, 0755, true);
    }

    $zip = new ZipArchive();
    $opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    if ($opened !== true) {
        Log::error('ZIP OPEN FAILED', ['code' => $opened]);
        return response()->json(['message' => 'Cannot create zip'], 500);
    }

    foreach ($items as $item) {
        if ($item['type'] === 'file') {
            $this->addFileToZip($zip, $item['id'], $user);
        }

        if ($item['type'] === 'folder') {
            $this->addFolderToZip($zip, $item['id'], $user);
        }
    }

    $zip->close();

    Log::info('ZIP READY', [
        'exists' => file_exists($zipPath),
        'size'   => file_exists($zipPath) ? filesize($zipPath) : 0,
        'path'   => $zipPath,
    ]);

    return response()->download($zipPath, $zipName, [
        'Content-Type' => 'application/zip',
    ])->deleteFileAfterSend(true);
}


    /* =========================
       Add single file to zip
    ========================= */

private function addFileToZip(ZipArchive $zip, $fileId, $user, $prefix = '')
{
    $file = Files::find($fileId);

    if (!$file || $file->owner_id !== $user->id) {
        return;
    }

    // path จริงบนเครื่อง
    $fullPath = base_path('public/' . $file->file_path);


    // ชื่อไฟล์ใน zip (ใช้ชื่อเดิม หรือ original_name)
    $zipName = $prefix . ($file->original_name ?? basename($file->file_path));

    Log::info('ADD FILE TO ZIP', [
        'file_id'   => $file->id,
        'db_path'   => $file->file_path,
        'full_path' => $fullPath,
        'exists'    => file_exists($fullPath),
    ]);

    if (!file_exists($fullPath)) {
        Log::error('FILE NOT FOUND', [
            'full_path' => $fullPath,
        ]);
        return;
    }

    $zip->addFile($fullPath, $zipName);
}

    /* =========================
       Add folder (recursive)
    ========================= */
private function addFolderToZip(ZipArchive $zip, $folderId, $user, $prefix = '')
{
    $folder = Folders::find($folderId);
    if (!$folder || $folder->owner_id !== $user->id) {
        return;
    }

    $folderPrefix = $prefix . $folder->name . '/';
    $zip->addEmptyDir($folderPrefix);

    foreach ($folder->files as $file) {
        $this->addFileToZip($zip, $file->id, $user, $folderPrefix);
    }

    foreach ($folder->children as $child) {
        $this->addFolderToZip($zip, $child->id, $user, $folderPrefix);
    }
}

public function downloadFile($permissionId)
{
    $user = JWTAuth::parseToken()->authenticate();

    // 1️⃣ หา permission ที่ส่งมา
    $perm = Permissions::where('id', $permissionId)
        ->where('allow_download', 1)
        ->firstOrFail();

    // 2️⃣ กรณี permission เป็น file (เคสเดิม)
    if ($perm->target_type === 'file') {
        $file = Files::findOrFail($perm->target_id);
    }

    // 3️⃣ กรณี permission เป็น folder → resolve file
    elseif ($perm->target_type === 'folder') {
        $fileId = request('file_id'); // 👈 frontend ต้องส่งมา
        if (!$fileId) {
            abort(400, 'Missing file_id');
        }

        $file = Files::where('id', $fileId)
            ->where('folder_id', $perm->target_id)
            ->firstOrFail();
    } else {
        abort(403);
    }

    // 4️⃣ เช็กว่า user มีสิทธิ์จริงไหม
    if (
        $perm->scope === 'private' &&
        $perm->shared_with_user !== $user->id &&
        $perm->owner_id !== $user->id
    ) {
        abort(403);
    }

    $fullPath = base_path('public/' . ltrim($file->file_path, '/'));

    if (!file_exists($fullPath)) {
        abort(404, 'File not found');
    }

    return response()->download(
        $fullPath,
        $file->original_name ?? basename($fullPath)
    );
}


public function downloadSharedFolderZip($permissionId)
{
    $user = JWTAuth::parseToken()->authenticate();

    $perm = Permissions::where('id', $permissionId)
        ->where('target_type', 'folder')
        ->where('shared_with_user', $user->id)
        ->where('allow_download', 1)
        ->firstOrFail();

    $folder = Folders::with(['files', 'children'])->findOrFail($perm->target_id);


    $zipPath = storage_path('app/tmp/shared_' . uniqid() . '.zip');
    File::ensureDirectoryExists(dirname($zipPath));

    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    $this->addSharedFolderToZip($zip, $folder);

    $zip->close();

    return response()
        ->download($zipPath, $folder->name . '.zip')
        ->deleteFileAfterSend(true);
}

private function addSharedFolderToZip(
    ZipArchive $zip,
    $folder,
    $prefix = ''
) {
    $count = 0;

    $folder->loadMissing(['files', 'children']);

    $safeName = trim($folder->name) ?: 'folder_' . $folder->id;
    $folderPrefix = $prefix . $safeName . '/';

    if ($zip->locateName($folderPrefix) === false) {
        $zip->addEmptyDir($folderPrefix);
    }

    foreach ($folder->files as $file) {

        if (!$file->file_path) continue;

        $fullPath = base_path('public/' . ltrim($file->file_path, '/'));
        if (!file_exists($fullPath)) continue;

        $zipName = $folderPrefix . ($file->original_name ?? basename($file->file_path));

        if ($zip->locateName($zipName) === false) {
            $zip->addFile($fullPath, $zipName);
            $count++;
        }
    }

    foreach ($folder->children as $child) {
        if ($child->id === $folder->id) continue;
        $count += $this->addSharedFolderToZip($zip, $child, $folderPrefix);
    }

    return $count;
}

public function downloadSharedMultiple(Request $request)
{
    $user = JWTAuth::parseToken()->authenticate();
    $items = $request->input('items');

    if (!is_array($items) || empty($items)) {
        return response()->json(['message' => 'items must be array'], 400);
    }

    $fileName = 'shared_download_' . time() . '_' . rand(100,999) . '.zip';
    $zipPath = storage_path('app/tmp/' . $fileName);
    File::ensureDirectoryExists(dirname($zipPath));

    $zip = new \ZipArchive();
    if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
        return response()->json(['message' => 'Cannot create zip'], 500);
    }

    $totalAdded = 0;

    // ⭐ กันไฟล์ซ้ำ เมื่อเลือกทั้ง folder + file
    $selectedFolderIds = collect($items)
        ->where('target_type', 'folder')
        ->pluck('target_id')
        ->toArray();

    foreach ($items as $item) {

    // 🔁 รองรับทั้ง target_type (front) และ type (apidog)
    $targetType = $item['target_type'] ?? $item['type'] ?? null;
    $permissionId = $item['permission_id'] ?? null;
    $targetId = $item['target_id'] ?? null;

    // ❌ ขาดข้อมูลหลัก ข้าม
    if (!$permissionId || !$targetType || !$targetId) {
        continue;
    }

    // 🔑 permission = root
$perm = Permissions::where('id', $permissionId)
    ->where('allow_download', 1)
    ->where(function ($q) use ($user) {
        $q->where('shared_with_user', $user->id)
          ->orWhere('shared_email', $user->email)
          ->orWhere('owner_id', $user->id); // ✅ FIX
    })
    ->first();


    if (!$perm) continue;

    /* ========= FILE ========= */
    if ($targetType === 'file') {

        $file = Files::find($targetId);
        if (!$file || !$file->file_path) continue;

        // 🔒 ต้องอยู่ใต้ shared root
        if (
            $perm->target_type === 'folder' &&
            $file->folder_id &&
            !$this->isUnderSharedRoot($file->folder_id, $perm->target_id)
        ) {
            continue;
        }

        // ⭐ ถ้าไฟล์อยู่ใต้ folder ที่เลือกแล้ว → ข้าม
        foreach ($selectedFolderIds as $fid) {
            if (
                $file->folder_id &&
                $this->isUnderSharedRoot($file->folder_id, $fid)
            ) {
                continue 2;
            }
        }

        $fullPath = base_path('public/' . ltrim($file->file_path, '/'));
        if (!file_exists($fullPath)) continue;

        $zipName = $file->original_name ?? basename($file->file_path);

        if ($zip->locateName($zipName) === false) {
            $zip->addFile($fullPath, $zipName);
            $totalAdded++;
        }
    }

    /* ========= FOLDER ========= */
    if ($targetType === 'folder') {

        $folder = Folders::find($targetId);
        if (!$folder) continue;

        // 🔒 ต้องอยู่ใต้ shared root
        if (
            $perm->target_type === 'folder' &&
            !$this->isUnderSharedRoot($folder->id, $perm->target_id)
        ) {
            continue;
        }

        $totalAdded += $this->addSharedFolderToZip(
            $zip,
            $folder
        );
    }
}


    $zip->close();

if ($totalAdded === 0) {
    if (file_exists($zipPath)) unlink($zipPath);

    \Log::warning('ZIP empty', [
        'user_id' => $user->id,
        'items' => $items
    ]);

    return response()->json([
        'message' => 'No files allowed to download'
    ], 403); // ✅ ไม่ใช้ 404
}


    return response()
        ->download($zipPath, $fileName)
        ->deleteFileAfterSend(true);
}
private function isUnderSharedRoot(int $folderId, int $sharedRootId): bool
{
    while ($folderId) {
        if ($folderId === $sharedRootId) return true;

        $folder = Folders::find($folderId);
        if (!$folder) break;

        $folderId = $folder->parent_id;
    }
    return false;
}

//=====================PUBLIC================================
public function downloadPublicSharedMultiple(Request $request)
{
    $items = $request->input('items');

    if (!is_array($items) || empty($items)) {
        return response()->json(['message' => 'items must be array'], 400);
    }

    $fileName = 'public_download_' . time() . '_' . rand(100, 999) . '.zip';
    $zipPath = storage_path('app/tmp/' . $fileName);
    File::ensureDirectoryExists(dirname($zipPath));

    $zip = new \ZipArchive();
    if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
        return response()->json(['message' => 'Cannot create zip'], 500);
    }

    $totalAdded = 0;

    foreach ($items as $item) {

        if (
            !isset($item['permission_id'], $item['target_type'], $item['target_id'])
        ) continue;

        $perm = Permissions::where('id', $item['permission_id'])
            ->where('scope', 'public')
            ->where('allow_download', 1)
            ->first();

        if (!$perm) continue;

        /* ========= FILE ========= */
        if ($item['target_type'] === 'file') {

            $file = Files::find($item['target_id']);
            if (!$file || !$file->file_path) continue;

            // 🔒 file ต้องอยู่ใต้ shared root
            if (
                $file->folder_id &&
                $perm->target_type === 'folder' &&
                !$this->isUnderSharedRoot($file->folder_id, $perm->target_id)
            ) {
                continue;
            }

            $fullPath = base_path('public/' . ltrim($file->file_path, '/'));
            if (!file_exists($fullPath)) continue;

            $zipName = $file->original_name ?? basename($file->file_path);

            if ($zip->locateName($zipName) === false) {
                $zip->addFile($fullPath, $zipName);
                $totalAdded++;
            }
        }

        /* ========= FOLDER ========= */
        if ($item['target_type'] === 'folder') {

            $folder = Folders::find($item['target_id']);
            if (!$folder) continue;

            // 🔒 ต้องอยู่ใต้ shared root
            if (
                $folder->id !== $perm->target_id &&
                !$this->isUnderSharedRoot($folder->parent_id, $perm->target_id)
            ) {
                continue;
            }

            $totalAdded += $this->addSharedFolderToZipPublic(
                $zip,
                $folder,
                $perm->id
            );
        }
    }

    $zip->close();

    if ($totalAdded === 0 || !file_exists($zipPath)) {
        if (file_exists($zipPath)) unlink($zipPath);
        return response()->json(['message' => 'No downloadable files'], 404);
    }

    return response()
        ->download($zipPath, $fileName)
        ->deleteFileAfterSend(true);
}




private function addSharedFolderToZipPublic(
    \ZipArchive $zip,
    $folder,
    int $permissionId,
    string $basePath = ''
) {
    $count = 0;

    // 🔒 ใช้ permission ตัวแม่ตัวเดียว
    $perm = Permissions::find($permissionId);
    if (!$perm || $perm->scope !== 'public' || !$perm->allow_download) {
        return 0;
    }

    $folderName = $folder->folder_name ?? $folder->name ?? 'folder';
    $currentPath = ltrim($basePath . '/' . $folderName, '/');

    /* ========= FILES ========= */
    $files = Files::where('folder_id', $folder->id)->get();

    foreach ($files as $file) {
        if (!$file->file_path) continue;

        $fullPath = base_path('public/' . ltrim($file->file_path, '/'));
        if (!file_exists($fullPath)) continue;

        $zipName = $currentPath . '/' . (
            $file->original_name ?? basename($file->file_path)
        );

        if ($zip->locateName($zipName) === false) {
            $zip->addFile($fullPath, $zipName);
            $count++;
        }
    }

    /* ========= SUB FOLDERS ========= */
    $subFolders = Folders::where('parent_id', $folder->id)->get();

    foreach ($subFolders as $sub) {
        // 👇 ใช้ per_id เดิม สืบทอดลงไป
        $count += $this->addSharedFolderToZipPublic(
            $zip,
            $sub,
            $permissionId,
            $currentPath
        );
    }

    return $count;
}



// public function downloadPublicFile($permissionId)
// {
//     $perm = Permissions::where('id', $permissionId)
//         ->where('target_type', 'file')
//         ->where('scope', 'public')
//         ->where('allow_download', 1)
//         ->firstOrFail();

//     $file = Files::findOrFail($perm->target_id);

//     $fullPath = base_path('public/' . ltrim($file->file_path, '/'));
//     if (!file_exists($fullPath)) {
//         abort(404, 'File not found');
//     }

//     return response()->download(
//         $fullPath,
//         $file->original_name ?? basename($fullPath)
//     );
// }

// public function downloadPublicFile($permissionId)
// {
//     // 1️⃣ หา permission (public)
//     $perm = Permissions::where('id', $permissionId)
//         ->where('scope', 'public')
//         ->where('allow_download', 1)
//         ->firstOrFail();

//     // 2️⃣ resolve file
//     if ($perm->target_type === 'file') {
//         // เคสแชร์ไฟล์ตรง
//         $file = Files::findOrFail($perm->target_id);
//     }
//     elseif ($perm->target_type === 'folder') {
//         // เคสแชร์โฟลเดอร์ → ต้องรับ file_id
//         $fileId = request('file_id');
//         if (!$fileId) {
//             abort(400, 'Missing file_id');
//         }

//         $file = Files::where('id', $fileId)
//             ->where('folder_id', $perm->target_id)
//             ->firstOrFail();
//     }
//     else {
//         abort(403);
//     }

//     // 3️⃣ โหลดไฟล์จริง
//     $fullPath = base_path('public/' . ltrim($file->file_path, '/'));
//     if (!file_exists($fullPath)) {
//         abort(404, 'File not found');
//     }

// return $this->safeDownload(
//     $fullPath,
//     $file->original_name ?? basename($fullPath)
// );
// }
// private function safeDownload(string $fullPath, string $filename)
// {
//     if (!file_exists($fullPath)) {
//         abort(404, 'File not found');
//     }

//     $filename = str_replace(['"', "'"], '', $filename);

//     return response()->streamDownload(function () use ($fullPath) {
//         readfile($fullPath);
//     }, $filename, [
//         'Content-Type' => mime_content_type($fullPath),
//         'Content-Disposition' =>
//             "attachment; filename*=UTF-8''" . rawurlencode($filename),
//         'Access-Control-Expose-Headers' =>
//             'Content-Disposition, Content-Length',
//     ]);
// }
public function downloadByFileId($fileId)
{
    $file = Files::findOrFail($fileId);

    $fullPath = base_path('public/' . ltrim($file->file_path, '/'));

    if (!file_exists($fullPath)) {
        abort(404);
    }

    return response()->download(
        $fullPath,
        $file->original_name ?? basename($fullPath)
    );
}




public function downloadSingleAdmin($id)
{
    // ถ้าหน้านี้โดน protect ด้วย middleware admin อยู่แล้ว
    // จะ parse token หรือไม่ก็ได้ แต่ใส่ไว้ก็ไม่เสียหาย
    JWTAuth::parseToken()->authenticate();

    $file = Files::findOrFail($id);

    // ❌ ไม่ต้องเช็ค owner
    $fullPath = base_path('public/' . ltrim($file->file_path, '/'));

    if (!file_exists($fullPath)) {
        abort(404, 'File not found');
    }

    return response()->download(
        $fullPath,
        $file->name ?? basename($fullPath),
        [
            'Content-Type' => mime_content_type($fullPath),
        ]
    );
}

public function downloadMultipleAdmin(Request $request)
{
    // แค่ auth ก็พอ (หน้า admin กันไว้แล้ว)
    JWTAuth::parseToken()->authenticate();

    $items = $request->input('items');

    if (!is_array($items) || empty($items)) {
        return response()->json(['message' => 'Invalid request'], 400);
    }

    $zipName = 'admin_download_' . time() . '.zip';
    $zipDir  = storage_path('app/tmp');
    $zipPath = $zipDir . DIRECTORY_SEPARATOR . $zipName;

    if (!File::exists($zipDir)) {
        File::makeDirectory($zipDir, 0755, true);
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return response()->json(['message' => 'Cannot create zip'], 500);
    }

    foreach ($items as $item) {
        if ($item['type'] === 'file') {
            $this->addFileToZipAdmin($zip, $item['id']);
        }

        if ($item['type'] === 'folder') {
            $this->addFolderToZipAdmin($zip, $item['id']);
        }
    }

    $zip->close();

    return response()->download($zipPath, $zipName, [
        'Content-Type' => 'application/zip',
    ])->deleteFileAfterSend(true);
}
private function addFileToZipAdmin(ZipArchive $zip, $fileId, $prefix = '')
{
    $file = Files::find($fileId);
    if (!$file) return;

    $fullPath = base_path('public/' . ltrim($file->file_path, '/'));
    if (!file_exists($fullPath)) return;

    $zipName = $prefix . ($file->original_name ?? basename($file->file_path));
    $zip->addFile($fullPath, $zipName);
}
private function addFolderToZipAdmin(ZipArchive $zip, $folderId, $prefix = '')
{
    $folder = Folders::find($folderId);
    if (!$folder) return;

    $folderPrefix = $prefix . $folder->name . '/';
    $zip->addEmptyDir($folderPrefix);

    foreach ($folder->files as $file) {
        $this->addFileToZipAdmin($zip, $file->id, $folderPrefix);
    }

    foreach ($folder->children as $child) {
        $this->addFolderToZipAdmin($zip, $child->id, $folderPrefix);
    }
}

public function destroyFolderAdmin($id)
{
    JWTAuth::parseToken()->authenticate();

    $folder = Folders::findOrFail($id);

    DB::transaction(function () use ($folder) {

        $this->deleteFolderRecursiveAdmin($folder);

        // 🔥 ลบ permissions ของโฟลเดอร์หลัก
        Permissions::where('target_type', 'folder')
            ->where('target_id', $folder->id)
            ->delete();

        $folder->delete();
    });

    return response()->json([
        'success' => true,
        'message' => 'แอดมินลบโฟลเดอร์และไฟล์ทั้งหมดเรียบร้อยแล้ว',
        'folder_id' => $id,
    ]);
}
private function deleteFolderRecursiveAdmin(Folders $folder)
{
    $folder->load(['ManyFile', 'children']);

    // 1️⃣ ลบไฟล์ในโฟลเดอร์นี้
    foreach ($folder->ManyFile as $file) {

        $fullPath = base_path('public/' . ltrim($file->file_path, '/'));

        if (File::exists($fullPath)) {
            File::delete($fullPath);
        }

        Permissions::where('target_type', 'file')
            ->where('target_id', $file->id)
            ->delete();

        $file->delete();
    }

    // 2️⃣ ลบโฟลเดอร์ลูก
    foreach ($folder->children as $childFolder) {

        $this->deleteFolderRecursiveAdmin($childFolder);

        Permissions::where('target_type', 'folder')
            ->where('target_id', $childFolder->id)
            ->delete();

        $childFolder->delete();
    }

    // 3️⃣ ลบ directory จริง
    $folderPath = base_path('public/' . ltrim($folder->url_file, '/'));

    if (File::isDirectory($folderPath)) {
        File::deleteDirectory($folderPath);
    }
}





}
