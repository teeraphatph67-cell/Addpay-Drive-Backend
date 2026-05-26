<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Response;
use App\Models\Permissions;
use App\Models\User;
use App\Models\Files;
use App\Models\Folders;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Illuminate\Support\Facades\Validator;
use FFMpeg\FFMpeg;
use FFMpeg\Coordinate\TimeCode;
use Illuminate\Support\Facades\Log;
class PermissionsCon extends Controller
{
    protected $response;

    public function __construct()
    {
        $this->response = new Response();
    }

public function enablePublic(Request $request, $type, $id)
{
    $user = JWTAuth::parseToken()->authenticate();

$validator = Validator::make($request->all(), [
    'allow_view'     => 'required|boolean',
    'allow_edit'     => 'required|boolean',
    'allow_download' => 'required|boolean',
]);

if ($validator->fails()) {
    return response()->json([
        'success' => false,
        'message' => 'ข้อมูลไม่ถูกต้อง',
        'errors'  => $validator->errors(),
    ], 422);
}

$validated = $validator->validated();


    /* -------------------------------
     | 2. กัน logic พลาด
     | edit = true → view ต้อง true
     -------------------------------- */
    $allowView     = (bool) $validated['allow_view'];
    $allowEdit     = (bool) $validated['allow_edit'];
    $allowDownload = (bool) $validated['allow_download'];

    if ($allowEdit) {
        $allowView = true;
    }

    /* -------------------------------
     | 3. ตรวจ target + owner
     -------------------------------- */
    if ($type === 'file') {
        Files::where('id', $id)
            ->where('owner_id', $user->id)
            ->firstOrFail();
    } elseif ($type === 'folder') {
        Folders::where('id', $id)
            ->where('owner_id', $user->id)
            ->firstOrFail();
    } else {
        return response()->json([
            'success' => false,
            'message' => 'type ต้องเป็น file หรือ folder'
        ], 400);
    }

    /* -------------------------------
     | 4. หา public permission เดิม
     -------------------------------- */
    $perm = Permissions::where('target_type', $type)
        ->where('target_id', $id)
        ->where('scope', 'public')
        ->first();

    /* -------------------------------
     | 5. ถ้ายังไม่มี → สร้างใหม่
     |    ถ้ามีแล้ว → update
     -------------------------------- */
    if (!$perm) {
        do {
            $token = Str::random(48);
        } while (
            Permissions::where('shared_link_token', $token)->exists()
        );

        $perm = Permissions::create([
            'target_type'       => $type,
            'target_id'         => $id,
            'owner_id'          => $user->id,
            'shared_with_user'  => null,
            'shared_link_token' => $token,
            'scope'             => 'public',
            'allow_view'        => $allowView,
            'allow_edit'        => $allowEdit,
            'allow_download'    => $allowDownload,
            'created_at' => Carbon::now('Asia/Bangkok'),

        ]);
    } else {
        // อัปเดต permission (ไม่สร้าง token ใหม่)
        $perm->update([
            'allow_view'     => $allowView,
            'allow_edit'     => $allowEdit,
            'allow_download' => $allowDownload,
        ]);
    }

    /* -------------------------------
     | 6. สร้าง public url
     -------------------------------- */
    $publicUrl = url('/api/v1/share/' . $perm->shared_link_token);

    return response()->json([
        'success' => true,
        'message' => 'เปิดแชร์ผ่านลิงก์แล้ว',
        'data'    => [
            'public_url' => $publicUrl,
            'permission' => $perm
        ]
    ]);
}


     public function disablePublic($type, $id)
    {
        $user = JWTAuth::parseToken()->authenticate();

        // ยืนยัน owner
        if ($type === 'file') Files::where('id', $id)->where('owner_id', $user->id)->firstOrFail();
        else Folders::where('id', $id)->where('owner_id', $user->id)->firstOrFail();

        Permissions::where('target_type',$type)
                   ->where('target_id',$id)
                   ->where('scope','public')
                   ->delete();

        return response()->json(['success'=>true,'message'=>'ปิดแชร์ผ่านลิงก์แล้ว']);
    }

// public function viewByToken($token)
// {
//     $perms = Permissions::where('shared_link_token', $token)
//         ->where('allow_view', 1)
//         ->get();

//     if ($perms->isEmpty()) {
//         abort(404);
//     }

//     // ================= 🌍 PUBLIC =================
//     $publicPerm = $perms->first(fn ($p) => $p->scope === 'public');

//     if ($publicPerm) {

//         if ($publicPerm->expires_at && now()->gt($publicPerm->expires_at)) {
//             return response()->json([
//                 'success' => false,
//                 'message' => 'ลิงก์นี้หมดอายุแล้ว'
//             ], 410);
//         }

//         return $this->loadTargetByPermission($publicPerm);
//     }

//     // ================= 🔐 PRIVATE =================
//     try {
//         $user = JWTAuth::parseToken()->authenticate();
//     } catch (\Exception $e) {
//         return response()->json([
//             'success' => false,
//             'message' => 'กรุณาเข้าสู่ระบบ'
//         ], 401);
//     }

//     // $perm = $perms->first(function ($p) use ($user) {
//     //     return
//     //         $p->owner_id === $user->id ||
//     //         $p->shared_with_user === $user->id ||
//     //         (
//     //             $p->shared_email &&
//     //             strtolower($p->shared_email) === strtolower($user->email)
//     //         );
//     // });
// $perm = $perms->first(function ($p) use ($user) {

//     // ✅ owner ของ resource ดูได้เสมอ
//     if ($p->target_type === 'folder') {
//         $folder = Folders::find($p->target_id);
//         if ($folder && $folder->owner_id === $user->id) {
//             return true;
//         }
//     }

//     if ($p->target_type === 'file') {
//         $file = Files::find($p->target_id);
//         if ($file && $file->owner_id === $user->id) {
//             return true;
//         }
//     }

//     // permission ปกติ
//     return
//         $p->owner_id === $user->id ||
//         $p->shared_with_user === $user->id ||
//         (
//             $p->shared_email &&
//             strtolower($p->shared_email) === strtolower($user->email)
//         );
// });

//     if (! $perm) {
//         return response()->json([
//             'success' => false,
//             'message' => 'คุณไม่มีสิทธิ์ดู'
//         ], 403);
//     }

//     return $this->loadTargetByPermission($perm);
// }

public function viewByToken($token)
{
    $perms = Permissions::where('shared_link_token', $token)
        ->where('allow_view', 1)
        ->get();

    if ($perms->isEmpty()) {
        abort(404);
    }

    // ================= 🌍 PUBLIC =================
    $publicPerm = $perms->first(fn ($p) => $p->scope === 'public');

    if ($publicPerm) {

        if ($publicPerm->expires_at && now()->gt($publicPerm->expires_at)) {
            return response()->json([
                'success' => false,
                'message' => 'ลิงก์นี้หมดอายุแล้ว'
            ], 410);
        }

        return $this->loadTargetByPermission($publicPerm);
    }

    // ================= 🔐 PRIVATE =================
    try {
        $user = JWTAuth::parseToken()->authenticate();
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'กรุณาเข้าสู่ระบบ'
        ], 401);
    }

    // 🔑 token = สิทธิ์ (email เป็น metadata เท่านั้น)
    $perm = $perms->first(function ($p) use ($user) {

        // 👑 เจ้าของ resource
        if ($p->owner_id === $user->id) {
            return true;
        }

        // 👤 แชร์ด้วย user_id
        if (
            $p->shared_with_user &&
            $p->shared_with_user === $user->id
        ) {
            return true;
        }

        // 📧 แชร์ด้วย email → แค่มี token ก็พอ
        if ($p->shared_email) {
            return true;
        }

        return false;
    });

    if (! $perm) {
        return response()->json([
            'success' => false,
            'message' => 'คุณไม่มีสิทธิ์ดู'
        ], 403);
    }

    return $this->loadTargetByPermission($perm);
}


private function loadTargetByPermission(Permissions $perm)
{
    if (! $perm->id) {
        return response()->json([
            'success' => false,
            'message' => 'permission ไม่ถูกต้อง'
        ], 403);
    }

    // ================= FILE =================
    if ($perm->target_type === 'file') {

        $file = Files::where('is_trashed', 0)->find($perm->target_id);

        if (! $file) {
            return response()->json([
                'success' => false,
                'message' => 'ไฟล์ถูกลบแล้ว'
            ], 410);
        }

        return response()->json([
            'success' => true,
            'type' => 'file',
            'permission_id' => $perm->id,
            'permission' => $perm,
            'data' => [
                'id' => $file->id,
                'name' => $file->file_name,
                'mime_type' => $file->mime_type,
                'size_mb' => $file->size_mb,
                'file_path' => $file->file_path,
                'owner_id' => $file->owner_id,
                'created_at' => $file->created_at,
            ]
        ]);
    }

    // ================= FOLDER =================
    $folder = Folders::where('is_trashed', 0)->find($perm->target_id);

    if (! $folder) {
        return response()->json([
            'success' => false,
            'message' => 'โฟลเดอร์ถูกลบแล้ว'
        ], 410);
    }

    $owner = \App\Models\User::find($folder->owner_id);

    $folderData = $this->buildFolderTree($folder, $perm);

    $folderData['owner_name'] =
        $owner?->name ??
        $owner?->username ??
        'Anonymous';

    return response()->json([
        'success' => true,
        'type' => 'folder',
        'permission_id' => $perm->id,
        'permission' => $perm,
        'data' => $folderData
    ]);
}

private function buildFolderTree(Folders $folder, Permissions $basePerm)
{
    /**
     * 👁️ permission สำหรับ browse (inherit)
     */
    $sharedPerm = $basePerm;

    /**
     * 🔐 permission ของโฟลเดอร์นี้จริง
     */
    $folderPerm = $this->resolvePermissionForTarget(
        $basePerm,
        'folder',
        $folder->id
    ) ?? $basePerm; // 🔥 fallback สำคัญมาก

    // ---------- child folders ----------
    $childrenFolders = Folders::where('parent_id', $folder->id)
        ->where('is_trashed', 0)
        ->orderBy('name')
        ->get()
        ->map(function ($child) use ($folderPerm) {

            $childFolderPerm = $this->resolvePermissionForTarget(
                $folderPerm,
                'folder',
                $child->id
            ) ?? $folderPerm;

            return [
                'type' => 'folder',

                'shared_permission_id' => $folderPerm->id,
                'resource_permission_id' => $childFolderPerm->id,

                'permission' => $childFolderPerm,

                'data' => $this->buildFolderTree($child, $childFolderPerm),
            ];
        })
        ->values()
        ->all();

    // ---------- files ----------
    $files = Files::where('folder_id', $folder->id)
        ->where('is_trashed', 0)
        ->orderBy('file_name')
        ->get()
        ->map(function ($file) use ($folderPerm) {

            $filePerm = $this->resolvePermissionForTarget(
                $folderPerm,
                'file',
                $file->id
            ) ?? $folderPerm;

            return [
                'type' => 'file',

                'shared_permission_id' => $folderPerm->id,
                'resource_permission_id' => $filePerm->id,

                'permission' => $filePerm,

                'data' => [
                    'id' => $file->id,
                    'name' => $file->file_name,
                    'mime_type' => $file->mime_type,
                    'size_mb' => $file->size_mb,
                    'created_at' => $file->created_at,
                    'preview_url' => url(
                        "/api/v1/preview/{$folderPerm->shared_link_token}/{$file->id}"
                    ),
                ],
            ];
        })
        ->values()
        ->all();

    return [
        'id' => $folder->id,
        'name' => $folder->name,
        'parent_id' => $folder->parent_id,
        'owner_id' => $folder->owner_id,
        'created_at' => $folder->created_at,
        'updated_at' => $folder->updated_at,

        'shared_permission_id' => $sharedPerm->id,
        'resource_permission_id' => $folderPerm->id,

        'permission' => $folderPerm,
        'items' => array_merge($childrenFolders, $files),
    ];
}
// private function resolvePermissionForTarget(Permissions $basePerm, string $type, int $targetId)
// {
//     // หา permission ที่ specific กว่า (ถ้ามี)
//     $perm = Permissions::where('target_type', $type)
//         ->where('target_id', $targetId)
//         ->where(function ($q) use ($basePerm) {
//             $q->where('owner_id', $basePerm->owner_id)
//               ->orWhere('shared_with_user', $basePerm->shared_with_user)
//               ->orWhere('shared_email', $basePerm->shared_email);
//         })
//         ->where('allow_view', true)
//         ->first();

//     // ❗ ถ้าไม่มี → inherit จาก parent
//     return $perm ?: $basePerm;
// }
private function resolvePermissionForTarget(
    Permissions $basePerm,
    string $type,
    int $targetId
) {
    // ===============================
    // 👑 resource owner → full access
    // ===============================
    $resourceOwnerId = null;

    if ($type === 'folder') {
        $resourceOwnerId = Folders::where('id', $targetId)->value('owner_id');
    } elseif ($type === 'file') {
        $resourceOwnerId = Files::where('id', $targetId)->value('owner_id');
    }

    if ($resourceOwnerId && $resourceOwnerId === $basePerm->owner_id) {
        return $basePerm; // owner inherit base permission
    }

    // ===============================
    // 🔐 permission ปกติ
    // ===============================
    $perm = Permissions::where('target_type', $type)
        ->where('target_id', $targetId)
        ->where('allow_view', true)
        ->where(function ($q) use ($basePerm) {
            $q->where('owner_id', $basePerm->owner_id)
              ->orWhere('shared_with_user', $basePerm->shared_with_user)
              ->orWhere('shared_email', $basePerm->shared_email);
        })
        ->first();

    return $perm ?: $basePerm; // inherit
}

public function previewByToken($token, $fileId)
{
    $perm = Permissions::where('shared_link_token', $token)
        ->where('allow_view', true)
        ->first();

    if (! $perm) {
        return response()->json([
            'success' => false,
            'message' => 'ไม่มีสิทธิ์ดูไฟล์นี้'
        ], 403);
    }

    // ตรวจว่าไฟล์อยู่ภายใต้ permission นี้จริง
    if ($perm->target_type === 'file' && (int)$perm->target_id !== (int)$fileId) {
        return response()->json([
            'success' => false,
            'message' => 'ไฟล์นี้ไม่อยู่ใน permission นี้'
        ], 403);
    }

    if ($perm->target_type === 'folder') {
        $file = Files::where('id', $fileId)
            ->where('is_trashed', 0)
            ->first();
    } else {
        $file = Files::find($fileId);
    }

    if (! $file) {
        return response()->json([
            'success' => false,
            'message' => 'ไม่พบไฟล์'
        ], 404);
    }

    $path = base_path('public/' . $file->file_path);



if (! file_exists($path)) {
    return response()->json([
        'success' => false,
        'message' => 'ไฟล์ไม่อยู่ในระบบ'
    ], 404);
}

return response()->file($path, [
    'Content-Type' => $file->mime_type
]);


}



public function preview($token, $fileId)
{
    $perms = Permissions::where('shared_link_token', $token)->get();
    if ($perms->isEmpty()) abort(404);

    // 🌍 public
    $publicPerm = $perms->first(fn ($p) =>
        $p->scope === 'public' && $p->allow_view
    );

    if (! $publicPerm) {
        // 🔐 private ต้อง login
        try {
            $user = JWTAuth::parseToken()->authenticate();
        } catch (\Exception $e) {
            abort(401);
        }

        $perm = $perms->first(fn ($p) =>
            $p->allow_view &&
            (
                $p->owner_id === $user->id ||
                $p->shared_with_user === $user->id ||
                (
                    $p->shared_email &&
                    strtolower($p->shared_email) === strtolower($user->email)
                )
            )
        );

        if (! $perm) abort(403);
    }

    $file = Files::findOrFail($fileId);

    // ✅ ใช้ file_path จาก DB ตรง ๆ
    $path = base_path('public/' . ltrim($file->file_path, '/'));


    if (!file_exists($path)) {
        abort(404, 'File not found');
    }

    return response()->file($path, [
        'Content-Type' => $file->mime_type,
        'Content-Disposition' => 'inline'
    ]);
}


function buildFolderPath($folder)
{
    $paths = [];

    while ($folder) {
        array_unshift($paths, $folder->name);
        $folder = $folder->parent;
    }

    return implode('/', $paths);
}

private function getRootFolder($folder)
{
    while ($folder && $folder->parent_id) {
        $folder = $folder->parent;
    }

    return $folder;
}

public function thumbnail($shareToken, $fileId)
{
    $perm = Permissions::where('shared_link_token', $shareToken)->firstOrFail();
    $file = Files::findOrFail($fileId);

    if ($perm->expires_at && now()->gt($perm->expires_at)) abort(410);
    if (!$perm->allow_view) abort(403);

    $sourcePath = base_path('public/' . ltrim($file->file_path, '/'));
    if (!file_exists($sourcePath)) abort(404);

    // 🔥 สำคัญ
$thumbDir  = base_path('public/thumbnails');
$thumbPath = $thumbDir . '/' . $file->id . '.jpg';


    if (!is_dir($thumbDir)) mkdir($thumbDir, 0755, true);

    if (file_exists($thumbPath)) {
        return response()->file($thumbPath);
    }

    try {

        if (str_starts_with($file->mime_type, 'image/')) {
            $manager = new ImageManager(new Driver());
            $image = $manager->read($sourcePath)->cover(240, 240)->toJpeg(75);
            file_put_contents($thumbPath, $image->toString());
        }

elseif (str_contains($file->mime_type, 'pdf')) {

    $gs = '"C:/Program Files/gs/gs10.06.0/bin/gswin64c.exe"';

    // 🔥 FIX: copy ไป temp path ภาษาอังกฤษก่อน
    $safePdf = storage_path('app/tmp/pdf_' . $file->id . '.pdf');

    if (!copy($sourcePath, $safePdf)) {
        abort(500, 'Cannot copy PDF for thumbnail');
    }

    $cmd = sprintf(
        '%s -dSAFER -dNOPAUSE -dBATCH -sDEVICE=jpeg -dFirstPage=1 -dLastPage=1 -r150 -dJPEGQ=75 -sOutputFile=%s %s',
        $gs,
        escapeshellarg($thumbPath),
        escapeshellarg($safePdf)
    );

    exec($cmd . ' 2>&1', $out, $code);

    @unlink($safePdf); // 🧹 ลบ temp

    if ($code !== 0 || !file_exists($thumbPath)) {
        Log()->error('PDF thumbnail failed', [
            'cmd'  => $cmd,
            'out'  => $out,
            'code'=> $code,
        ]);
        abort(500, 'Cannot generate PDF thumbnail');
    }
}


        elseif (str_starts_with($file->mime_type, 'video/')) {
            $ffmpeg = '"C:/ffmpeg/bin/ffmpeg.exe"';
            $cmd = sprintf(
                '%s -y -i %s -ss 00:00:01 -vframes 1 -q:v 2 %s',
                $ffmpeg,
                escapeshellarg($sourcePath),
                escapeshellarg($thumbPath)
            );
            exec($cmd, $out, $code);
            if ($code !== 0 || !file_exists($thumbPath)) abort(204);
        }

        else {
            abort(204);
        }

    } catch (\Throwable $e) {
        Log::error('Thumbnail error', [
            'file_id' => $file->id,
            'mime' => $file->mime_type,
            'error' => $e->getMessage(),
        ]);
        abort(204);
    }

    return response()->file($thumbPath, [
        'Content-Type' => 'image/jpeg',
        'Cache-Control' => 'public, max-age=604800',
    ]);
}


public function createForItem(Request $request, $type, $id)
{
    $user = JWTAuth::parseToken()->authenticate(); // owner

    // validate type + owner exists
    if ($type === 'file') {
        Files::where('id', $id)->where('owner_id', $user->id)->firstOrFail();
    } elseif ($type === 'folder') {
        Folders::where('id', $id)->where('owner_id', $user->id)->firstOrFail();
    } else {
        return response()->json([
            'success' => false,
            'message' => 'type ต้องเป็น file หรือ folder'
        ], 400);
    }

    $this->validate($request, [
        'email' => 'required|email',
        'allow_view' => 'nullable|boolean',
        'allow_edit' => 'nullable|boolean',
        'allow_download' => 'nullable|boolean',
    ]);

    $email = strtolower($request->input('email'));

    // 🔑 หา user ในระบบ
    $sharedUser = User::whereRaw('LOWER(email)=?', [$email])->first();

    /**
     * ✅ FIX หลัก: ถ้าไม่มี user → ห้ามแชร์ private
     */
    if (!$sharedUser) {
        return response()->json([
            'success' => false,
            'message' => 'ไม่พบผู้ใช้นี้ในระบบ ไม่สามารถแชร์แบบส่วนตัวได้'
        ], 422);
    }

    /**
     * 🔑 STEP 1: หา token เดิมของ item (ถ้ามี)
     */
    $existingToken = Permissions::where('target_type', $type)
        ->where('target_id', $id)
        ->where('owner_id', $user->id)
        ->where('scope', 'private')
        ->value('shared_link_token');

    /**
     * 🔑 STEP 2: ถ้ายังไม่มี token → สร้างใหม่
     */
    $token = $existingToken ?? Str::random(48);

    /**
     * 🔑 STEP 3: ป้องกันแชร์ซ้ำ (user ในระบบเท่านั้น)
     */
    $alreadyShared = Permissions::where('target_type', $type)
        ->where('target_id', $id)
        ->where('owner_id', $user->id)
        ->where('shared_with_user', $sharedUser->id)
        ->exists();

    if ($alreadyShared) {
        return response()->json([
            'success' => false,
            'message' => 'ผู้ใช้นี้ถูกแชร์ไปแล้ว'
        ], 409);
    }

    /**
     * 🔑 STEP 4: create permission
     */
    $perm = Permissions::create([
        'target_type' => $type,
        'target_id'   => $id,
        'owner_id'    => $user->id,
        'shared_with_user' => $sharedUser->id, // ⭐ บังคับมี user
        'shared_email' => null,                // ⭐ ไม่ใช้ email แล้ว
        'shared_link_token' => $token,
        'scope' => 'private',
        'allow_view' => $request->input('allow_view', 1),
        'allow_edit' => $request->input('allow_edit', 1),
        'allow_download' => $request->input('allow_download', 1),
        'created_at' => Carbon::now()->toDateTimeString(),
    ]);

    $link = url("/api/v1/share/{$token}");

    return response()->json([
        'success' => true,
        'message' => "แชร์สำเร็จให้ {$email}",
        'data' => [
            'permission' => $perm,
            'shared_link' => $link,
            'shared_link_token' => $token,
        ],
    ], 201);
}

 public function generatePrivateLink(Request $request, $type, $id)
    {
        $owner = JWTAuth::parseToken()->authenticate();

        // validate type and owner
        if ($type === 'file') {
            Files::where('id', $id)->where('owner_id', $owner->id)->firstOrFail();
        } elseif ($type === 'folder') {
            Folders::where('id', $id)->where('owner_id', $owner->id)->firstOrFail();
        } else {
            return response()->json(['success'=>false,'message'=>'type ต้องเป็น file หรือ folder'],400);
        }

        $this->validate($request, [
            'shared_email' => 'nullable|email',
            'expires_at'   => 'nullable|date',
            'allow_view'   => 'nullable|boolean',
            'allow_edit'   => 'nullable|boolean',
            'allow_download'=> 'nullable|boolean',
        ]);

        // ถ้ามี shared_email และตรงกับ user ในระบบ ให้เก็บ shared_with_user แทน
        $sharedEmail = $request->input('shared_email') ? strtolower($request->input('shared_email')) : null;
        $sharedUser = null;
        if ($sharedEmail) {
            $sharedUser = \App\Models\User::whereRaw('LOWER(email)=?', [$sharedEmail])->first();
        }

        // สร้าง token แบบ unique
        do {
            $token = Str::random(48);
        } while (Permissions::where('shared_link_token', $token)->exists());

        $perm = Permissions::create([
            'target_type'       => $type,
            'target_id'         => $id,
            'owner_id'          => $owner->id,
            'shared_with_user'  => $sharedUser ? $sharedUser->id : null,
            'shared_email'      => $sharedUser ? null : $sharedEmail,
            'shared_link_token' => $token,
            'scope'             => 'private', // สำคัญ: private link (ต้องล็อกอิน)
            'allow_view'        => $request->input('allow_view', 1),
            'allow_edit'        => $request->input('allow_edit', 0),
            'allow_download'    => $request->input('allow_download', 1),
            'created_at'        => Carbon::now()->toDateTimeString(),
            // ถ้ามีคอลัมน์ expires_at ให้ใส่ด้วย:
            'expires_at'        => $request->input('expires_at') ?? null,
        ]);

        $publicUrl = url('/api/v1/share/' . $perm->shared_link_token);

        return response()->json([
            'success' => true,
            'message' => 'สร้าง private link สำเร็จ (ต้องล็อกอินเพื่อเข้าดู)',
            'data' => [
                'public_url' => $publicUrl,
                'permission' => $perm
            ]
        ], 201);
    }

public function listSharedWithMe()
{
    $user = JWTAuth::parseToken()->authenticate();

    $items = collect();

    /*
    |------------------------------------------------------------------
    | 1. แชร์โฟลเดอร์ (folder → inherit)
    |------------------------------------------------------------------
    */
    $folderPerms = Permissions::where('scope', 'private')
        ->where('target_type', 'folder')
        ->where('allow_view', 1)
        ->where(function ($q) use ($user) {
            $q->where('shared_with_user', $user->id)
              ->orWhereRaw('LOWER(shared_email) = ?', [strtolower($user->email)]);
        })
        ->get();

    foreach ($folderPerms as $perm) {

        $rootFolder = Folders::find($perm->target_id);
        if (!$rootFolder || $rootFolder->is_trashed) {
            continue;
        }
        $items->push(array_merge(
    $this->transformFolderBase($rootFolder),
    [
        'shared_permission_id'   => $perm->id,
        'resource_permission_id' => $perm->id,
        'parent_id'              => null,
        'allow_view'             => (bool) $perm->allow_view,
        'allow_edit'             => (bool) $perm->allow_edit,
        'allow_download'         => (bool) $perm->allow_download,
        'children'               => [],
    ]
));

        // 🔹 children (inherit)
        $children = $this->getAllChildren($rootFolder->id);

        foreach ($children['folders'] as $folder) {
            if ($folder->is_trashed) continue;

        $items->push(array_merge(
    $this->transformFolderBase($folder),
    [
        'shared_permission_id'   => $perm->id, // root context
        'resource_permission_id' => $this->resolveResourcePermissionId(
            'folder',
            $folder->id,
            $perm->id
        ),
        'parent_id'              => $folder->parent_id,
        'allow_view'             => (bool) $perm->allow_view,
        'allow_edit'             => (bool) $perm->allow_edit,
        'allow_download'         => (bool) $perm->allow_download,
        'children'               => [],
    ]
));

        }

        foreach ($children['files'] as $file) {
            if ($file->is_trashed) continue;
        $items->push(array_merge(
    $this->transformFileBase($file),
    [
        'shared_permission_id'   => $perm->id,
        'resource_permission_id' => $this->resolveResourcePermissionId(
            'file',
            $file->id,
            $perm->id
        ),
        'parent_id'              => $file->folder_id,
        'allow_view'             => (bool) $perm->allow_view,
        'allow_edit'             => (bool) $perm->allow_edit,
        'allow_download'         => (bool) $perm->allow_download,
        'children'               => [],
    ]
));

        }
    }

    /*
    |------------------------------------------------------------------
    | 2. แชร์ไฟล์เดี่ยว (file → root)
    |------------------------------------------------------------------
    */
    $filePerms = Permissions::where('scope', 'private')
        ->where('target_type', 'file')
        ->where('allow_view', 1)
        ->where(function ($q) use ($user) {
            $q->where('shared_with_user', $user->id)
              ->orWhereRaw('LOWER(shared_email) = ?', [strtolower($user->email)]);
        })
        ->get();

    foreach ($filePerms as $perm) {

        $file = Files::find($perm->target_id);
        if (!$file || $file->is_trashed) continue;

        // 🔥 สำคัญ: file ที่แชร์ตรง = root
        $items->push(array_merge(
            $this->transformFileBase($file),
            [
                'permission_id' => $perm->id,
                        'source_permission_id' => $perm->id, 
                'inherited'     => false,
                'role'          => $perm->role,
                'parent_id'     => null, // ⭐ FIX จุดหาย
                'allow_view'    => (bool) $perm->allow_view,
                'allow_edit'    => (bool) $perm->allow_edit,
                'allow_download'=> (bool) $perm->allow_download,
                'children'      => [],
            ]
        ));
    }

    /*
    |------------------------------------------------------------------
    | 3. unique + build tree
    |------------------------------------------------------------------
    */
    $items = $items
        ->unique(fn ($i) => $i['type'] . '_' . $i['id'])
        ->values();

    $tree = $this->buildTree($items);

    return response()->json([
        'success' => true,
        'data'    => $tree
    ]);
}

private function resolveResourcePermissionId($type, $id, $fallback)
{
    $perm = Permissions::where('scope', 'private')
        ->where('target_type', $type)
        ->where('target_id', $id)
        ->where('allow_view', 1)
        ->first();

    return $perm?->id ?? $fallback;
}

private function buildTree($items)
{
    $map = [];
    $tree = [];

    // 1. map ด้วย key ที่ไม่ชน
    foreach ($items as $item) {
        $key = $item['type'] . '_' . $item['id'];
        $map[$key] = $item;
    }

    // 2. ผูก parent → child
    foreach ($map as $key => &$node) {

        if (!empty($node['parent_id'])) {

            $parentKey = 'folder_' . $node['parent_id']; // ✅ parent มีแต่ folder

            if (isset($map[$parentKey])) {
                $map[$parentKey]['children'][] = &$node;
                continue;
            }
        }

        // root
        $tree[] = &$node;
    }

    return array_values($tree);
}


private function getAllChildren($folderId)
{
    $files = Files::where('folder_id', $folderId)->get();
    $folders = Folders::where('parent_id', $folderId)->get();

    $allFiles = collect($files);
    $allFolders = collect($folders);

    foreach ($folders as $folder) {
        $child = $this->getAllChildren($folder->id);
        $allFiles = $allFiles->merge($child['files']);
        $allFolders = $allFolders->merge($child['folders']);
    }

    return [
        'files'   => $allFiles,
        'folders' => $allFolders,
    ];
}
private function transformFolderBase(Folders $folder)
{
    return [
        'id'         => $folder->id,
        'name'       => $folder->name,
        'type'       => 'folder',
        'parent_id'  => $folder->parent_id,
        'drive_id'   => $folder->drive_id,
        'owner_id'   => $folder->owner_id,
        'url_file' => $folder->url_file,
        'is_trashed' => $folder->is_trashed,
        'created_at' => $folder->created_at,
        'updated_at' => $folder->updated_at,
    ];
}
private function transformFileBase(Files $file)
{
    return [
        'id'         => $file->id,
        'name'       => $file->file_name,
        'type'       => 'file',
        'folder_id'  => $file->folder_id,
        'parent_id'  => $file->folder_id, // สำหรับ tree
        'drive_id'   => $file->drive_id,
        'owner_id'   => $file->owner_id,
        'file_name' => $file->file_name,
        'file_path' => $file->file_path,
        'mime_type' => $file->mime_type,
        'file_ext'  => $file->file_ext,
        'size_mb'   => $file->size_mb,
        'created_at'=> $file->created_at,
    ];
}






    public function list(Request $request, $type, $id)
{
    $owner = JWTAuth::parseToken()->authenticate();

    // ตรวจว่าเป็นเจ้าของ
    if ($type === 'file') {
        Files::where('id', $id)->where('owner_id', $owner->id)->firstOrFail();
    } elseif ($type === 'folder') {
        Folders::where('id', $id)->where('owner_id', $owner->id)->firstOrFail();
    } else {
        return response()->json(['success'=>false,'message'=>'type ต้องเป็น file หรือ folder'],400);
    }

    $permissions = Permissions::with('sharedUser:id,name,email')
        ->where('target_type', $type)
        ->where('target_id', $id)
        ->where('owner_id', $owner->id)
        ->get()
        ->map(function ($p) {
            return [
                'id' => $p->id,
                'shared_to' => $p->sharedUser
                    ? [
                        'type' => 'user',
                        'name' => $p->sharedUser->name,
                        'email'=> $p->sharedUser->email
                    ]
                    : [
                        'type' => 'email',
                        'email'=> $p->shared_email
                    ],
                'permissions' => [
                    'view' => (bool)$p->allow_view,
                    'edit' => (bool)$p->allow_edit,
                    'download' => (bool)$p->allow_download,
                ],
                'expires_at' => $p->expires_at,
                'link' => url('/api/v1/share/' . $p->shared_link_token),
                'shared_link_token' =>$p->shared_link_token
                
            ];
        });

    return response()->json([
        'success' => true,
        'data' => $permissions
    ]);
}

public function update(Request $request, $permissionId)
{
    $owner = JWTAuth::parseToken()->authenticate();

    $perm = Permissions::where('id', $permissionId)
        ->where('owner_id', $owner->id)
        ->firstOrFail();

    $this->validate($request, [
        'allow_view'     => 'nullable|boolean',
        'allow_edit'     => 'nullable|boolean',
        'allow_download' => 'nullable|boolean',
        'expires_at'     => 'nullable|date'
    ]);

    $perm->update($request->only([
        'allow_view',
        'allow_edit',
        'allow_download',
        'expires_at'
    ]));

    return response()->json([
        'success' => true,
        'message' => 'อัปเดตสิทธิ์เรียบร้อย',
        'data' => $perm
    ]);
}

public function destroy($permissionId)
{
    $owner = JWTAuth::parseToken()->authenticate();

    $perm = Permissions::where('id', $permissionId)
        ->where('owner_id', $owner->id)
        ->firstOrFail();

    $perm->delete();

    return response()->json([
        'success' => true,
        'message' => 'ลบการแชร์เรียบร้อย'
    ]);
}


    public function revokePrivateLink($type, $id)
    {
        $owner = JWTAuth::parseToken()->authenticate();

        // ยืนยัน owner
        if ($type === 'file') Files::where('id',$id)->where('owner_id',$owner->id)->firstOrFail();
        else Folders::where('id',$id)->where('owner_id',$owner->id)->firstOrFail();

        Permissions::where('target_type',$type)
                   ->where('target_id',$id)
                   ->where('scope','private')
                   ->whereNotNull('shared_link_token')
                   ->delete();

        return response()->json(['success'=>true,'message'=>'ปิด private link เรียบร้อยแล้ว']);
    }



}






