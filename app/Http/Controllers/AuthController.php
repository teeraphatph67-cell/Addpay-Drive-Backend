<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Drive;
use App\Models\Folders;
use App\Models\Files;
use Carbon\Carbon;
use App\Models\Response;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Google_Client;
class AuthController extends Controller
{
    protected $response;

    public function __construct()
    {
        $this->response = new Response();
    }

public function loginWithGoogle(Request $request)
{
    $idToken = $request->input('token');

    if (!$idToken) {
        return response()->json(['error' => 'Missing Google token'], 400);
    }

    $client = new \Google_Client([
        'client_id' => env('GOOGLE_CLIENT_ID'),
    ]);

    $payload = $client->verifyIdToken($idToken);

    if (!$payload) {
        return response()->json(['error' => 'Invalid Google token'], 401);
    }

    $email       = $payload['email'] ?? null;
    $name        = $payload['name'] ?? 'Google User';
    $avatar_url  = $payload['picture'] ?? null;

    if (!$email) {
        return response()->json(['error' => 'Google account has no email'], 400);
    }

    // หา user
    $user = User::where('email', $email)->first();

    if (!$user) {
        // -------- create user ใหม่ --------
        $baseUsername = explode('@', $email)[0];

        // 🔥 แก้ตรงนี้: ลบจุดและอักขระแปลก
        $baseUsername = preg_replace('/[^a-zA-Z0-9ก-๙_]/u', '', $baseUsername);

        $username = $baseUsername;
        $i = 1;

        while (User::where('username', $username)->exists()) {
            $username = $baseUsername . $i;
            $i++;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'username' => $username,
            'avatar_url' => $avatar_url,
            'provider' => 'google',
            'provider_id' => $payload['sub'],
            'password' => Hash::make(uniqid('google_', true)),
            'quota_total_mb' => 100,
            'quota_used_mb' => 0,
        ]);
} else {
    // -------- update avatar --------
    if ($avatar_url && (!$user->avatar_url || $user->provider === 'google')) {
        $user->update([
            'avatar_url' => $avatar_url,
        ]);
    }

    // -------- 🔥 normalize username ของ user เก่า --------
    $normalizedUsername = preg_replace('/[^a-zA-Z0-9ก-๙_]/u', '', $user->username);

    if ($normalizedUsername !== $user->username) {

        // กันซ้ำ
        $finalUsername = $normalizedUsername;
        $i = 1;
        while (User::where('username', $finalUsername)->where('id', '!=', $user->id)->exists()) {
            $finalUsername = $normalizedUsername . $i;
            $i++;
        }

        // path เก่า / ใหม่
        $oldPath = base_path("public/uploads/{$user->username}");
        $newPath = base_path("public/uploads/{$finalUsername}");

        // rename folder ถ้ามี
        if (is_dir($oldPath) && !is_dir($newPath)) {
            rename($oldPath, $newPath);
        }

        // update username
        $user->update([
            'username' => $finalUsername,
        ]);
    }
}
    // drive
    Drive::firstOrCreate(
        ['user_id' => $user->id],
        ['name' => 'My Drive']
    );

    // folder จริง
    $basePath = base_path("public/uploads/{$user->username}/my-drive");
    if (!is_dir($basePath)) {
        mkdir($basePath, 0755, true);
    }

    $token = JWTAuth::fromUser($user);

    return response()->json([
        'token' => $token,
        'token_type' => 'bearer',
        'expires_in' => auth()->factory()->getTTL() * 60,
        'user' => $user->fresh(),
    ]);
}

    public function User_Drive($id)
    {
        try {
            $user = User::with([
                'drive',
                'drive.foldersActive.filesActive',
                'drive.foldersActive.childrenRecursiveActive',
                'drive.filesActive',
            ])->find($id);

            if (!$user) {
                return $this->response->error('ไม่พบผู้ใช้งาน', 404);
            }

            return $this->response->success($user, 'แสดงข้อมูลผู้ใช้งาน', 200);

        } catch (\Exception $e) {
            return $this->response->error('เกิดข้อผิดพลาด: ' . $e->getMessage(), 500);
        }
    }

    public function User_Drive_Trash($id)
    {
        try {
            $user = User::with([
                'drive',
                'drive.trashed_folders.trashedChildrenRecursive',
                'drive.trashed_files',
                'drive.trashedFiles'
            ])->find($id);

            if (!$user) {
                return $this->response->error('ไม่พบผู้ใช้งาน', 404);
            }

            return $this->response->success($user, 'แสดงข้อมูลในถังขยะ', 200);

        } catch (\Exception $e) {
            return $this->response->error('เกิดข้อผิดพลาด: ' . $e->getMessage(), 500);
        }
    }

public function OpenFile(Request $request, $id)
{
    $file = Files::findOrFail($id);

    $file->last_opened_at = now(); // เวลาไทย
    $file->last_opened_by = optional($request->user())->id;

    $file->save();

    return response()->json([
        'success' => true,
        'message' => 'อัปเดตเวลาเปิดไฟล์แล้ว',
        'last_opened_at' => $file->last_opened_at->format('d/m/Y H:i'),
    ]);
}



   public function OpenFile_view($id)
{
    $user = User::with('drive')->find($id);

    if (! $user || ! $user->drive) {
        return $this->response->error('ไม่พบผู้ใช้งานหรือ drive', 404);
    }

    $files = Files::with(['parentFolder', 'openedByUser'])
        ->where('drive_id', $user->drive->id)
        ->where('is_trashed', 0)
        ->orderByDesc('last_opened_at')
        ->get()
        ->map(function ($file) {
            $folderPath = $file->parentFolder
                ? $this->buildFolderPath($file->parentFolder)
                : null;

            return $this->formatFileWithPath($file, $folderPath);
        });

    return $this->response->success(
        ['user' => $user, 'files' => $files],
        'แสดงรายการที่เปิดล่าสุด',
        200
    );
}

  private function formatFileWithPath($file, $folderPath = null)
{
    // root
    if (! $folderPath) {
        $displayPath = 'My Drive';
        $fullPath = 'My Drive/' . $file->file_name;
    } else {
        // ตัด / หน้า–หลังให้ชัวร์
        $cleanPath = trim($folderPath, '/');

        $displayPath = $cleanPath;
        $fullPath = $cleanPath . '/' . $file->file_name;
    }

    return [
        'id' => $file->id,
        'name' => $file->file_name,
        'mime_type' => $file->mime_type ?? null,
        'size_mb' => $file->size_mb ?? null,
        'folder_id' => $file->folder_id,

        // เอาไว้โชว์ breadcrumb / location
        'parent_folder' => $displayPath,

        // path เต็มแบบไม่มี / เกิน

'last_opened_at' => $file->last_opened_at
    ? Carbon::createFromFormat(
        'Y-m-d H:i:s',
        $file->getRawOriginal('last_opened_at'),
        'Asia/Bangkok'
    )->format('d/m/Y H:i')
    : null,



        'last_opened_by' => $file->last_opened_by,
        'opened_by_user' => $file->openedByUser ? [
            'id' => $file->openedByUser->id,
            'name' => $file->openedByUser->name,
        ] : null,
    ];
}

    /**
     * สร้าง full path ของโฟลเดอร์ด้วยการไต่ parent chain
     * คืน string เช่น "งานสำคัญ/โปรเจค A"
     */
    private function buildFolderPath($folder)
    {
        $segments = [];
        $current = $folder;
        $safety = 0; // ป้องกัน infinite loop
        while ($current && $safety++ < 50) {
            $segments[] = $current->name;
            if (! method_exists($current, 'parent')) break;
            $current = $current->parent;
        }

        $segments = array_reverse($segments);
        return implode('/', $segments);
    }

    public function logout()
    {
        try {
            $token = JWTAuth::parseToken()->getToken();

            DB::table('jwt_blacklist')->insert([
                'token' => $token,
                'expires_at' => Carbon::now()->addHour() // แก้จาก now() เป็น Carbon::now()
            ]);

            return response()->json(['success' => true ,'message' => 'Logout สำเร็จ']);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

public function listUser()
{
    $users = User::leftJoin('drive', 'users.id', '=', 'drive.user_id')
        ->select(
            'users.id',
            'users.name',
            'users.email',
            'users.username',
            'users.avatar_url',
            'users.status',
            'users.created_at',
            'drive.id as drive_id'
        )
        ->get();

    return response()->json([
        'status' => true,
        'data' => $users
    ]);
}

public function AdminUserDrive($driveId)
{
    try {

        $authUser = auth()->user();

        if (!$authUser || $authUser->status !== 'admin') {
            return $this->response->error('ไม่มีสิทธิ์เข้าถึง', 403);
        }

        // หา drive 
        $drive = Drive::with([
            'user',
            'foldersActive.filesActive',
            'foldersActive.childrenRecursiveActive',
            'filesActive',
        ])->find($driveId);

        if (!$drive) {
            return $this->response->error('ไม่พบ Drive', 404);
        }

        return $this->response->success(
            $drive,
            'แสดงข้อมูล Drive ของผู้ใช้งาน (Admin)',
            200
        );

    } catch (\Exception $e) {
        return $this->response->error(
            'เกิดข้อผิดพลาด: ' . $e->getMessage(),
            500
        );
    }
}

public function login(Request $request)
{
    try {

        $this->validate($request, [
            'login'    => 'required', // email หรือ username
            'password' => 'required|string'
        ]);

        $login    = $request->input('login');
        $password = $request->input('password');

        // หา user จาก email หรือ username
        $user = User::where('email', $login)
            ->orWhere('username', $login)
            ->first();

        if (!$user) {
            return response()->json([
                'error' => 'ไม่พบบัญชีผู้ใช้'
            ], 401);
        }

        // กัน Google account มากรอกรหัส
        if ($user->provider === 'google') {
            return response()->json([
                'error' => 'บัญชีนี้เข้าสู่ระบบด้วย Google'
            ], 403);
        }

        // เช็กรหัสผ่าน
        if (!Hash::check($password, $user->password)) {
            return response()->json([
                'error' => 'รหัสผ่านไม่ถูกต้อง'
            ], 401);
        }

        // gen token
        $token = JWTAuth::fromUser($user);

        return response()->json([
            'token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth()->factory()->getTTL() * 60,
            'user' => $user->fresh(),
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'error' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
        ], 500);
    }
}
private function createUser(array $data)
{
    $user = User::create([
        'name'            => $data['name'],
        'email'           => $data['email'],
        'username'        => $data['username'],
        'password'        => Hash::make($data['password']),
        'status'          => $data['status'] ?? 'user',
        'provider'        => $data['provider'] ?? 'local',
        'avatar_url'      => $data['avatar_url'] ?? null,
        'quota_total_mb'  => 100,
        'quota_used_mb'   => 0,
    ]);

    // drive
    Drive::firstOrCreate([
        'user_id' => $user->id
    ], [
        'name' => 'My Drive'
    ]);

    // folder
    $basePath = base_path("public/uploads/{$user->username}/my-drive");
    if (!is_dir($basePath)) {
        mkdir($basePath, 0755, true);
    }

    return $user;
}

public function register(Request $request)
{
    try {
        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'username' => 'required|string|unique:users,username',
            'password' => 'required|min:6',
            'avatar'   => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'ข้อมูลไม่ถูกต้อง',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // avatar
        $avatarUrl = null;

        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');

            $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
            $uploadPath = base_path('public/avatars');

            if (!is_dir($uploadPath)) {
                mkdir($uploadPath, 0755, true);
            }

            $file->move($uploadPath, $filename);

            $avatarUrl = "/avatars/{$filename}";
        }

        // create user
        $user = User::create([
            'name'            => $request->name,
            'email'           => $request->email,
            'username'        => $request->username,
            'password'        => Hash::make($request->password),
            'provider'        => 'local',
            'status'          => 'user',
            'avatar_url'      => $avatarUrl,
            'quota_total_mb'  => 100,
            'quota_used_mb'   => 0,
        ]);

        // 📁 drive
        Drive::create([
            'user_id' => $user->id,
            'name'    => 'My Drive',
        ]);

        // 📂 physical folder
        $basePath = base_path("public/uploads/{$user->username}/my-drive");
        if (!is_dir($basePath)) {
            mkdir($basePath, 0755, true);
        }

        return response()->json([
            'success' => true,
            'message' => 'สมัครสมาชิกสำเร็จ',
            'user' => $user->only([
                'id',
                'name',
                'email',
                'username',
                'avatar_url',
            ]),
        ], 201);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'error'   => $e->getMessage(),
        ], 500);
    }
}


public function setPassword(Request $request)
{
    $user = auth()->user();

    if ($user->provider !== 'google') {
        return response()->json([
            'error' => 'บัญชีนี้มีรหัสผ่านแล้ว'
        ], 400);
    }

    $this->validate($request, [
        'password' => 'required|min:6|confirmed',
    ]);

    $user->update([
        'password' => Hash::make($request->password),
        'provider' => 'local',
    ]);

    return response()->json([
        'message' => 'ตั้งรหัสผ่านสำเร็จ'
    ]);
}

public function update(Request $request, $id)
{
    try {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'ไม่พบผู้ใช้งาน',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name'       => 'sometimes|required|string|max:255',
            'email'      => 'sometimes|required|email|unique:users,email,' . $user->id,
            'password'   => 'nullable|min:6',
            'status'     => 'sometimes|required|in:admin,user',
            'avatar'     => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'ข้อมูลไม่ถูกต้อง',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $request->only(['name', 'email', 'status']);

        // 🔐 password
        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        // 📸 avatar
        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');

            $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
            $uploadPath = base_path('public/avatars');

            if (!is_dir($uploadPath)) {
                mkdir($uploadPath, 0755, true);
            }

            $file->move($uploadPath, $filename);

            $data['avatar_url'] = "/avatars/{$filename}";
        }

        $user->update($data);

        return response()->json([
            'success' => true,
            'message' => 'แก้ไขข้อมูลผู้ใช้สำเร็จ',
            'user' => $user->fresh()->only([
                'id',
                'name',
                'email',
                'username',
                'status',
                'avatar_url',
            ]),
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'เกิดข้อผิดพลาด',
            'error'   => $e->getMessage(),
        ], 500);
    }
}



public function destroy($id)
{
    DB::beginTransaction();

    try {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'ไม่พบผู้ใช้งาน',
            ], 404);
        }

        $deletedUser = $user->only(['id', 'name', 'email', 'status']);

        // =========================
        // 🧹 permissions (owner_id)
        // =========================
        DB::table('permissions')
            ->where('owner_id', $user->id)
            ->delete();

        // =========================
        // 🧹 files (owner_id)
        // =========================
        DB::table('files')
            ->where('owner_id', $user->id)
            ->delete();

        // =========================
        // 🧹 folders (owner_id)
        // =========================
        DB::table('folders')
            ->where('owner_id', $user->id)
            ->delete();

        // =========================
        // 🧹 drives (user_id)
        // =========================
        DB::table('drive')
            ->where('user_id', $user->id)
            ->delete();

        // =========================
        // 🗑 ลบโฟลเดอร์จริง
        // =========================
        $userPath = base_path("public/uploads/{$user->username}");

        $this->deleteDirectory($userPath);

        // =========================
        // ❌ ลบ user
        // =========================
        $user->delete();

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'ลบผู้ใช้และข้อมูลทั้งหมดสำเร็จ',
            'deleted_user' => $deletedUser,
        ], 200);

    } catch (\Exception $e) {
        DB::rollBack();

        return response()->json([
            'success' => false,
            'message' => 'เกิดข้อผิดพลาด',
            'error' => $e->getMessage(),
        ], 500);
    }
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

public function store(Request $request)
{

    try {
        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'username' => 'required|string|unique:users,username',
            'password' => 'required|min:6',
            'status'   => 'required|in:admin,user',

            // ✅ ใช้ avatar (ไม่ใช่ avatar_url)
            'avatar'   => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'ข้อมูลไม่ถูกต้อง',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // ======================
        // 📸 upload avatar
        // ======================
        $avatarUrl = null;

        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');

            $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
            $uploadPath = base_path('public/avatars');

            if (!is_dir($uploadPath)) {
                mkdir($uploadPath, 0755, true);
            }

            $file->move($uploadPath, $filename);

            // ✅ เก็บเป็น path
            $avatarUrl = "/avatars/{$filename}";
        }

        // ======================
        // 👤 create user
        // ======================
        $user = User::create([
            'name'            => $request->name,
            'email'           => $request->email,
            'username'        => $request->username,
            'password'        => Hash::make($request->password),
            'status'          => $request->status,
            'provider'        => 'local',
            'avatar_url'      => $avatarUrl,
            'quota_total_mb'  => 100,
            'quota_used_mb'   => 0,
        ]);

        Drive::create([
            'user_id' => $user->id,
            'name'    => 'My Drive',
        ]);

        $basePath = base_path("public/uploads/{$user->username}/my-drive");

if (!is_dir($basePath)) {
    mkdir($basePath, 0755, true);
}
        return response()->json([
            'success' => true,
            'message' => 'เพิ่มผู้ใช้สำเร็จ',
            'user' => $user->only([
                'id',
                'name',
                'email',
                'username',
                'status',
                'avatar_url'
            ]),
        ], 201);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'เกิดข้อผิดพลาด',
            'error'   => $e->getMessage(),
        ], 500);
    }
}





}

