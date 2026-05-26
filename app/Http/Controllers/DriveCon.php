<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Response;
use App\Models\Drive;
use App\Models\Folders;
use App\Models\Files;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;
class DriveCon extends Controller
{
    protected $response;

    public function __construct()
    {
        $this->response = new Response();
    }


    public function GetDriveById($id)
    {
        $drive = \App\Models\Drive::find($id);
        if (!$drive) {
            return $this->response->error('ไม่พบข้อมูลกล้องตาม ID ที่ระบุ', 404);
        }
        return $this->response->success($drive, 'ok', 200);
    }

    public function Drive_Folder($id)
    {
        try {
            // ดึง user + drive ของ user คนนี้
            $Drive = Drive::with('drive.Deive_Folder')->findOrFail($id);

            return $this->response->success($Drive, 'แสดงข้อมูลผู้ใช้งาน', 200);

        } catch (\Exception $e) {
            return $this->response->error('เกิดข้อผิดพลาด: ' . $e->getMessage(), 500);
        }
    }
    


    public function favoriteFile(Request $request, $id)
    {
        $file = Files::findOrFail($id);

        // ถ้าต้องบังคับ auth:
        // $userId = $request->user()->id ?? null;

        $file->is_starred = 1;
        $file->save();

        return response()->json([
            'success' => true,
            'message' => "ติดดาวไฟล์เรียบร้อย",
            'file'    => $file
        ]);
    }

    // เอาดาวออกจากไฟล์เดียว
    public function unfavoriteFile(Request $request, $id)
    {
        $file = Files::findOrFail($id);

        $file->is_starred = 0;
        $file->save();

        return response()->json([
            'success' => true,
            'message' => "เอาดาวออกเรียบร้อย",
            'file'    => $file
        ]);
    }

        public function bulkFavoriteFiles(Request $request)
    {
        $ids = $request->input('file_ids', []); // array
        if (!is_array($ids) || empty($ids)) {
            return response()->json(['success'=>false,'message'=>'file_ids required'], 400);
        }

        \App\Models\Files::whereIn('id', $ids)->update(['is_starred' => 1]);

        return response()->json(['success'=>true,'message'=>'ติดดาวไฟล์แล้ว']);
    }



    

    public function Favorite_Folder($id)
    {
        // ดึงโฟลเดอร์ + relation ฐานที่ต้องใช้
        $folder = Folders::with(['files', 'children'])->findOrFail($id);

        $this->favoriteRecursive($folder);

        return response()->json([
            'success' => true,
            'message' => "บันทึก '{$folder->name}' เป็นติดดาวเรียบร้อย",
            'folder_id' => $folder->id,
            'folder' => $folder->name,
        ]);
    }

    private function favoriteRecursive(Folders $folder)
    {
        // ⭐ 1) ติดดาวโฟลเดอร์ตัวเอง
        $folder->is_starred = 1;
        $folder->save();

        // ⭐ 2) ติดดาวไฟล์ทั้งหมดในโฟลเดอร์นี้
        foreach ($folder->files as $file) {
            $file->is_starred = 1;
            $file->save();
        }

        // ⭐ 3) ติดดาวโฟลเดอร์ลูกทั้งหมด (recursive)
        foreach ($folder->children as $child) {
            $child->loadMissing(['files', 'children']);
            $this->favoriteRecursive($child);
        }
    }


    public function RemoveFavorite($id)
    {
        $folder = Folders::with(['files', 'children'])->findOrFail($id);

        $this->unfavoriteRecursive($folder);

        return response()->json([
            'success' => true,
            'message' => "เอา '{$folder->name}' ออกจากติดดาวเรียบร้อย",
            'folder_id' => $folder->id,
            'folder' => $folder->name,
        ]);
    }

    private function unfavoriteRecursive(Folders $folder)
    {
        // ลบดาวโฟลเดอร์ตัวเอง
        $folder->is_starred = 0;
        $folder->save();

        // ลบดาวไฟล์ในโฟลเดอร์นี้
        foreach ($folder->files as $file) {
            $file->is_starred = 0;
            $file->save();
        }

        // ลบดาวโฟลเดอร์ลูกทั้งหมด
        foreach ($folder->children as $childFolder) {
            $childFolder->loadMissing(['files', 'children']);
            $this->unfavoriteRecursive($childFolder);
        }
    }


public function RemoveFavoriteFile($id)
{
    // ค้นหาไฟล์
    $file = Files::findOrFail($id);

    // ลบดาว
    $file->is_starred = 0;
    $file->save();

    return response()->json([
        'success' => true,
        'message' => "เอาไฟล์ '{$file->file_name}' ออกจากติดดาวเรียบร้อย",
        'file_id' => $file->id,
        'file_name' => $file->file_name,
    ]);
}




public function User_Drive_Starred($id)
{
    $user = User::with([
        'drive.starred_folders' => function ($q) {
            $q->with([
                'starredFiles',
                'childrenRecursiveActive',
            ]);
        },
        'drive.starred_files',
    ])->findOrFail($id);

    return $this->response->success([
        'starred_folders' => $user->drive->starred_folders,
        'starred_files'   => $user->drive->starred_files,
    ], 'Starred loaded');
}

private function deleteDirectory($dir)
{
    if (!is_dir($dir)) return;

    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;

        $path = $dir . DIRECTORY_SEPARATOR . $item;

        if (is_dir($path)) {
            $this->deleteDirectory($path);
        } else {
            unlink($path);
        }
    }

    rmdir($dir);
}

public function destroyDriveAndUser($driveId)
{
    DB::beginTransaction();

    try {
        $drive = Drive::find($driveId);

        if (!$drive) {
            return response()->json([
                'success' => false,
                'message' => 'ไม่พบ Drive',
            ], 404);
        }

        $user = User::find($drive->user_id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'ไม่พบเจ้าของ Drive',
            ], 404);
        }

        // =========================
        // 🧹 permissions (อิงไฟล์/โฟลเดอร์ใน drive นี้)
        // =========================
        DB::table('permissions')
            ->whereIn('target_id', function ($q) use ($driveId) {
                $q->select('id')
                  ->from('files')
                  ->where('drive_id', $driveId);
            })
            ->where('target_type', 'file')
            ->delete();

        DB::table('permissions')
            ->whereIn('target_id', function ($q) use ($driveId) {
                $q->select('id')
                  ->from('folders')
                  ->where('drive_id', $driveId);
            })
            ->where('target_type', 'folder')
            ->delete();

        // =========================
        // 🧹 files (drive_id)
        // =========================
        DB::table('files')
            ->where('drive_id', $driveId)
            ->delete();

        // =========================
        // 🧹 folders (drive_id)
        // =========================
        DB::table('folders')
            ->where('drive_id', $driveId)
            ->delete();

        // =========================
        // 🧹 drives (user_id)
        // =========================
        DB::table('drive')
            ->where('user_id', $user->id)
            ->delete();

        // =========================
        // 🗑 physical folder
        // =========================
        $userPath = base_path("public/uploads/{$user->username}");
        $this->deleteDirectory($userPath);

        // =========================
        // ❌ user
        // =========================
        $user->delete();

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'ลบ Drive + User + ข้อมูลทั้งหมดสำเร็จ',
            'user_id' => $user->id,
            'drive_id' => $driveId,
        ], 200);

    } catch (\Exception $e) {
        DB::rollBack();

        return response()->json([
            'success' => false,
            'message' => 'เกิดข้อผิดพลาด',
            'error'   => $e->getMessage(),
        ], 500);
    }
}


public function index(Request $request)
{
    $query = User::query()
        ->whereHas('drive'); // เอาเฉพาะ user ที่มี drive

    // 🔍 ค้นหาจาก name / email / username
    if ($request->filled('search')) {
        $query->where(function ($q) use ($request) {
            $q->where('name', 'like', '%' . $request->search . '%')
              ->orWhere('email', 'like', '%' . $request->search . '%')
              ->orWhere('username', 'like', '%' . $request->search . '%');
        });
    }

    // 📅 (ถ้าต้องการ) กรองวันสมัคร user
    if ($request->filled('date')) {
        $query->whereDate('created_at', $request->date);
    }

    return response()->json([
        'status' => true,
        'data' => $query
            ->orderBy('created_at', 'desc')
            ->get(['id','name','email','username','avatar_url','status','created_at'])
    ]);
}

public function browse(Request $request)
{
    $driveId  = $request->drive_id;
    $folderId = $request->folder_id;
    $q        = $request->q;

    // 🔧 FIX: normalize null
    if ($folderId === 'null' || $folderId === '') {
        $folderId = null;
    }

    // =========================
    // FOLDERS
    // =========================
    $folders = Folders::where('drive_id', $driveId)
        ->where('is_trashed', 0)
        ->when(
            is_null($folderId),
            fn ($query) => $query->whereNull('parent_id'),
            fn ($query) => $query->where('parent_id', $folderId)
        )
        ->when($q, fn ($query) =>
            $query->where('name', 'like', "%{$q}%")
        )
        ->select('id', 'parent_id', 'name', 'created_at')
        ->orderBy('name')
        ->get();

    // =========================
    // FILES
    // =========================
    $files = Files::where('drive_id', $driveId)
        ->where('is_trashed', 0)
        ->when(
            is_null($folderId),
            fn ($query) => $query->whereNull('folder_id'),
            fn ($query) => $query->where('folder_id', $folderId)
        )
        ->when($q, fn ($query) =>
            $query->where('file_name', 'like', "%{$q}%")
        )
        ->select(
            'id',
            'folder_id',
            'file_name',
            'file_ext',
            'mime_type',
            'size_mb',
            'created_at'
        )
        ->orderBy('file_name')
        ->get();

    return response()->json([
        'status'    => true,
        'mode'      => $q ? 'search' : 'browse',
        'drive_id'  => (int) $driveId,
        'folder_id' => $folderId,
        'folders'   => $folders,
        'files'     => $files,
    ]);
}


    /*
    |--------------------------------------------------------------------------
    | USER SEARCH
    |--------------------------------------------------------------------------
    */
    public function searchMyDrive(Request $request)
    {
        $user = auth()->user();

        $drive = Drive::where('user_id', $user->id)->firstOrFail();

        return $this->searchInDrive($request, $drive->id, 'user_search');
    }

    /*
    |--------------------------------------------------------------------------
    | ADMIN SEARCH
    |--------------------------------------------------------------------------
    */
    public function searchAdminDrive(Request $request, $driveId)
    {
        $user = auth()->user();

        if ($user->status !== 'admin') {
            abort(403, 'Admin only');
        }

        $drive = Drive::findOrFail($driveId);

        return $this->searchInDrive($request, $drive->id, 'admin_search');
    }

    /*
    |--------------------------------------------------------------------------
    | CORE SEARCH LOGIC (ใช้ร่วมกัน)
    |--------------------------------------------------------------------------
    */
private function searchInDrive(Request $request, $driveId, $mode)
{
    $q        = $request->q;
    $date     = $request->date;
    $dateFrom = $request->date_from;
    $dateTo   = $request->date_to;

    if (!$q && !$date && !$dateFrom && !$dateTo) {
        return response()->json([
            'status'  => false,
            'message' => 'Search parameter required'
        ], 422);
    }

    /*
    |--------------------------------------------------------------------------
    | PREPARE DATE RANGE
    |--------------------------------------------------------------------------
    */
    if ($dateFrom && !$dateTo) {
        $dateTo = $dateFrom;
    }

    if ($dateFrom && $dateTo) {
        $dateFrom = $dateFrom . ' 00:00:00';
        $dateTo   = $dateTo   . ' 23:59:59';
    }

    /*
    |--------------------------------------------------------------------------
    | FOLDERS QUERY
    |--------------------------------------------------------------------------
    */
    $foldersQuery = Folders::with('parent')
        ->where('drive_id', $driveId)
        ->where('is_trashed', 0);

    if ($q) {
        $foldersQuery->where('name', 'like', "%{$q}%");
    }

    if ($date) {
        $foldersQuery->whereDate('created_at', $date);
    }

    if ($dateFrom && $dateTo) {
        $foldersQuery->whereBetween('created_at', [$dateFrom, $dateTo]);
    }

    $folders = $foldersQuery->get()
        ->map(function ($folder) {
            return [
                'id'         => $folder->id,
                'name'       => $folder->name,
                'parent_id'  => $folder->parent_id,
                'created_at' => $folder->created_at,
                'breadcrumb' => $this->buildFolderBreadcrumb($folder),
            ];
        });

    /*
    |--------------------------------------------------------------------------
    | FILES QUERY (ลด N+1)
    |--------------------------------------------------------------------------
    */
    $filesQuery = Files::with('folder.parent')
        ->where('drive_id', $driveId)
        ->where('is_trashed', 0);

    if ($q) {
        $filesQuery->where('file_name', 'like', "%{$q}%");
    }

    if ($date) {
        $filesQuery->whereDate('created_at', $date);
    }

    if ($dateFrom && $dateTo) {
        $filesQuery->whereBetween('created_at', [$dateFrom, $dateTo]);
    }

    $files = $filesQuery->get()
        ->map(function ($file) {

            return [
                'id'         => $file->id,
                'file_name'  => $file->file_name,
                'folder_id'  => $file->folder_id,
                'file_path'  => $file->file_path,
                'created_at' => $file->created_at,
                'breadcrumb' => $file->folder
                    ? $this->buildFolderBreadcrumb($file->folder)
                    : [],
            ];
        });

    return response()->json([
        'status'   => true,
        'mode'     => $mode,
        'drive_id' => $driveId,
        'filters'  => [
            'q'         => $q,
            'date'      => $date,
            'date_from' => $request->date_from,
            'date_to'   => $request->date_to,
        ],
        'folders'  => $folders,
        'files'    => $files,
    ]);
}

    /*
    |--------------------------------------------------------------------------
    | BREADCRUMB BUILDER
    |--------------------------------------------------------------------------
    */
    private function buildFolderBreadcrumb($folder)
    {
        $breadcrumb = [];

        while ($folder) {
            array_unshift($breadcrumb, [
                'id'   => $folder->id,
                'name' => $folder->name
                
            ]);

            $folder = $folder->parent;
        }

        return $breadcrumb;
    }


    public function adminBrowse(Request $request, $driveId)
{
    $user = auth()->user();

    // 🔒 เช็คสิทธิ์ admin
    if ($user->status !== 'admin') {
        abort(403, 'Admin only');
    }

    $folderId = $request->folder_id;

    if ($folderId === 'null' || $folderId === '') {
        $folderId = null;
    }

    // เช็คว่า drive มีจริง
    $drive = Drive::findOrFail($driveId);

    /*
    |--------------------------------------------------------------------------
    | ถ้าเข้าโฟลเดอร์ ต้องเช็คว่าอยู่ใน drive นี้จริง
    |--------------------------------------------------------------------------
    */
    $currentFolder = null;

    if ($folderId) {
        $currentFolder = Folders::where('id', $folderId)
            ->where('drive_id', $driveId)
            ->firstOrFail();
    }

    /*
    |--------------------------------------------------------------------------
    | ดึงโฟลเดอร์ลูก
    |--------------------------------------------------------------------------
    */
    $folders = Folders::where('drive_id', $driveId)
        ->where('is_trashed', 0)
        ->when(
            is_null($folderId),
            fn ($q) => $q->whereNull('parent_id'),
            fn ($q) => $q->where('parent_id', $folderId)
        )
        ->orderBy('name')
        ->get()
        ->map(function ($folder) {
            return [
                'id'         => $folder->id,
                'name'       => $folder->name,
                'parent_id'  => $folder->parent_id,
                'created_at' => $folder->created_at,
            ];
        });

    /*
    |--------------------------------------------------------------------------
    | ดึงไฟล์ในโฟลเดอร์
    |--------------------------------------------------------------------------
    */
    $files = Files::where('drive_id', $driveId)
        ->where('is_trashed', 0)
        ->when(
            is_null($folderId),
            fn ($q) => $q->whereNull('folder_id'),
            fn ($q) => $q->where('folder_id', $folderId)
        )
        ->orderBy('file_name')
        ->get()
        ->map(function ($file) {
            return [
                'id'         => $file->id,
                'file_name'  => $file->file_name,
                'file_ext'   => $file->file_ext,
                'file_path'   => $file->file_path,
                'mime_type'  => $file->mime_type,
                'size_mb'    => $file->size_mb,
                'folder_id'  => $file->folder_id,
                'created_at' => $file->created_at,
            ];
        });

    return response()->json([
        'status'      => true,
        'mode'        => 'admin_browse',
        'drive_id'    => (int) $driveId,
        'folder_id'   => $folderId,
        'breadcrumb'  => $currentFolder
            ? $this->buildFolderBreadcrumb($currentFolder)
            : [],
        'folders'     => $folders,
        'files'       => $files,
    ]);
}




}


