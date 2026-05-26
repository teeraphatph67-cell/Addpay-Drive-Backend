<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Response;
use App\Models\CameraDetail;
// use App\Models\Animaldb;
// use App\Models\TableStream;
// use App\Models\Type_animal;
// use App\Models\uploadDocumentFile;
use Illuminate\Support\Facades\Hash;
class Addcontroller extends Controller
{
    protected $response;

    public function __construct()
    {
        $this->response = new Response();
    }

public function UpdateCamera(Request $request, $id)
{
    // หาเรคคอร์ดกล้องก่อน
    $cam = Cameradetail::find($id);
    if (!$cam) {
        return $this->response->error('ไม่พบข้อมูลกล้องตาม ID ที่ระบุ', 404);
    }

    // กำหนดกติกา validate
    $rulesPut = [
        'ip_address'    => 'required|string',     // จะเปลี่ยนเป็น required|ip ก็ได้ถ้าต้องการ
        'animal_id'     => 'required|integer',
        'camera_name'   => 'required|string',
        'model_camera'  => 'required|string',
        'rtsp_url'      => 'required|string',     // หรือ 'required|url' ถ้าเป็นลิงก์
        'angle_install' => 'required|string',
        'date_install'  => 'nullable|date',       // ถ้าบังคับใส่ก็เปลี่ยนเป็น required|date
        'status'        => 'required|string',
        'ip_vpn'        => 'nullable|string',     // หรือ required|string ถ้าจำเป็นต้องมี
    ];

    $rulesPatch = [
        'ip_address'    => 'sometimes|required|string',
        'animal_id'     => 'sometimes|required|integer',
        'camera_name'   => 'sometimes|required|string',
        'model_camera'  => 'sometimes|required|string',
        'rtsp_url'      => 'sometimes|required|string',
        'angle_install' => 'sometimes|required|string',
        'date_install'  => 'sometimes|nullable|date',
        'status'        => 'sometimes|required|string',
        'ip_vpn'        => 'sometimes|nullable|string',
    ];

    // เลือกใช้ rule ตาม method (PUT = ต้องส่งครบ, PATCH = แก้บางส่วนได้)
    $rules = $request->isMethod('put') ? $rulesPut : $rulesPatch;

    // Lumen: ถ้า validate ไม่ผ่านจะโยน 422 ให้เอง
    $this->validate($request, $rules);

    // อัปเดตเฉพาะฟิลด์ที่อนุญาต
    $cam->fill($request->only([
        'ip_address',
        'animal_id',
        'camera_name',
        'model_camera',
        'rtsp_url',
        'angle_install',
        'date_install',
        'status',
        'ip_vpn',
    ]));

    $cam->save();

    return $this->response->success($cam, 'อัปเดตข้อมูลกล้องสำเร็จ', 200);
}


    public function MoveCamera(Request $request, $id)
{
    // หาเรคคอร์ดตาม ID
    $findid = CameraDetail::find($id);
    if (!$findid) {
        return $this->response->error('ไม่พบข้อมูล ID ที่ระบุ', 404);
    }

    // Validation
  $rules = [
        'animal_id'  => 'required|integer'

    ];

    $this->validate($request, $rules);

    // เตรียมข้อมูลอัปเดต
  $updateData = [
        'animal_id'  => $request->animal_id,
    ];

 $findid->update($updateData);

    return $this->response->success($findid, 'อัปเดตข้อมูลสตรีมสำเร็จ', 200);
}


    /**
     * ดึงข้อมูลทั้งหมด
     */
     // ดึงผู้ใช้งานทั้งหมด
        // public function index($id)
        // {
        //     try {
        //         $users = Users::with(['role', 'agency'])->where('department', $id)->get();
        //         return $this->response->success($users, 'แสดงข้อมูลผู้ใช้งานทั้งหมด', 200);
        //     } catch (\Exception $e) {
        //         return $this->response->error('เกิดข้อผิดพลาด: ' . $e->getMessage(), 500);
        //     }
        // }

        // // ดึงผู้ใช้งาน 1 คน ตาม id
        // public function show($id)
        // {
        //     try {
        //         $user = Users::with(['role', 'agency'])->find($id);

        //         if (!$user) {
        //             return $this->response->error('ไม่พบผู้ใช้', 404);
        //         }

        //         return $this->response->success($user, 'ข้อมูลผู้ใช้', 200);
        //     } catch (\Exception $e) {
        //         return $this->response->error('เกิดข้อผิดพลาด: ' . $e->getMessage(), 500);
        //     }
        // }
        // // ดึงผู้ใช้งาน 1 คน ตาม id
        // public function roles()
        // {
        //     try {
        //         $user = Roles::with('users')->get();

        //         if (!$user) {
        //             return $this->response->error('ไม่พบผู้ใช้', 404);
        //         }

        //         return $this->response->success($user, 'ข้อมูลผู้ใช้', 200);
        //     } catch (\Exception $e) {
        //         return $this->response->error('เกิดข้อผิดพลาด: ' . $e->getMessage(), 500);
        //     }
        // }


    // addUsers
        public function Addcamera(Request $request)
        {
         $ip_address = $request['ip_address'];
         $animal_id = $request['animal_id'];
         $camera_name = $request['camera_name'];
         $model_camera = $request['model_camera'];
         $rtsp_url = $request['rtsp_url'];
         $angle_install = $request['angle_install'];
         $date_install = $request['date_install'];
         $status = $request['status'];
         $ip_vpn = $request['ip_vpn'];

        try {
                $user = Cameradetail::create([
                    'ip_address' => $ip_address,
                    'animal_id' => $animal_id,
                    'camera_name' => $camera_name,
                    'model_camera' => $model_camera,
                    'rtsp_url' => $rtsp_url,
                    'angle_install' => $angle_install,
                    'date_install' => $date_install,
                    'status' => $status,
                    'ip_vpn' => $ip_vpn,
                    
                ]);

                return $this->response->success($user, 'เพิ่มข้อมูลสำเร็จ', 200);
            } catch (\Exception $e) {
                return $this->response->error('เกิดข้อผิดพลาด: ' . $e->getMessage(), 500);
            }
    
        }

        //      public function Getanimal()
        // {
        //     try {
        //         $getanimal = CameraDetail::with('OneonOne')->get();
        //         return $this->response->success($getanimal, 'แสดงข้อมูลผู้ใช้งานทั้งหมด', 200);
        //     } catch (\Exception $e) {
        //         return $this->response->error('เกิดข้อผิดพลาด: ' . $e->getMessage(), 500);
        //     }
        // }

            public function camera_steram()
        {
            try {
                $getanimal = CameraDetail::with(['Stream'])->get();
                return $this->response->success($getanimal, 'แสดงข้อมูลผู้ใช้งานทั้งหมด', 200);
            } catch (\Exception $e) {
                return $this->response->error('เกิดข้อผิดพลาด: ' . $e->getMessage(), 500);
            }
        }

        public function GetcameraAnimal()
        {
            try {
                $GetcameraAnimal = Animaldb::with(['cameras'])->get();
                return $this->response->success($GetcameraAnimal, 'แสดงข้อมูลผู้ใช้งานทั้งหมด', 200);
            } catch (\Exception $e) {
                return $this->response->error('เกิดข้อผิดพลาด: ' . $e->getMessage(), 500);
            }
        }

         public function GetAnimalCameraStream()
        {
            try {
                $GetAnimalCameraStream = Animaldb::with(['typeAnimal','cameras','streams'])->get();
                return $this->response->success($GetAnimalCameraStream, 'แสดงข้อมูลผู้ใช้งานทั้งหมด', 200);
            } catch (\Exception $e) {
                return $this->response->error('เกิดข้อผิดพลาด: ' . $e->getMessage(), 500);
            }
        }



        public function Getcamera(Request $request)
        {
         $ip_address = $request['ip_address'];
         $animal_id = $request['animal_id'];
         $camera_name = $request['camera_name'];
         $rtsp_url = $request['rtsp_url'];
         $angle_install = $request['angle_install'];
         $date_install = $request['date_install'];
         $status = $request['status'];
         $ip_vpn = $request['ip_vpn'];

        try {
               $cameras = Cameradetail::all();
               return $this->response->success($cameras, 'ดึงข้อมูลสำเร็จ', 200);     
            } catch (\Exception $e) {
                 return $this->response->error('เกิดข้อผิดพลาด: ' . $e->getMessage(), 500);
            }
       


        
        }

          public function deleteById($id)
    {
        try {
            $cam = Cameradetail::find($id);
            if (!$cam) {
                return $this->response->error('ไม่พบข้อมูลกล้องตาม ID ที่ระบุ', 404);
            }

            // ถ้าเปิด SoftDeletes จะเป็น $cam->delete(); (เก็บลง deleted_at)
            $cam->delete(); // hard delete ถ้าไม่ได้ใช้ SoftDeletes

            // ตอบกลับตามต้องการ: 200 + ข้อความ หรือ 204 (ไม่มี body)
            return $this->response->success([], 'ลบกล้องสำเร็จ', 200);
            // หรือ return response('', 204);
        } catch (\Throwable $e) {
            return $this->response->error('เกิดข้อผิดพลาดในการลบข้อมูล: '.$e->getMessage(), 500);
        }
    }

    public function GetCameraById($id)
{
    $cam = \App\Models\Cameradetail::find($id);
    if (!$cam) {
        return $this->response->error('ไม่พบข้อมูลกล้องตาม ID ที่ระบุ', 404);
    }
    return $this->response->success($cam, 'ok', 200);
}

   public function CameraStatus()
        {
            try {
                $getanimal = CameraDetail::with(['Stream'])->get();
                return $this->response->success($getanimal, 'แสดงข้อมูลผู้ใช้งานทั้งหมด', 200);
            } catch (\Exception $e) {
                return $this->response->error('เกิดข้อผิดพลาด: ' . $e->getMessage(), 500);
            }
        }


//     public function UpdateCamera(Request $request, $id)
// {
//     $ip_address = $request['ip_address'];
//     $zoo_id = $request['zoo_id'];
//     $camera_position = $request['camera_position'];
//     $animal_name = $request['animal_name'];
//     $camera_url = $request['camera_url'];

//     try {
//         // หาข้อมูลกล้องก่อน
//         $camera = Cameradetail::find($id);

//         if (!$camera) {
//             return $this->response->error('ไม่พบข้อมูลกล้องที่ต้องการอัปเดต', 404);
//         }

//         // อัปเดตฟิลด์
//         $camera->ip_address = $ip_address;
//         $camera->zoo_id = $zoo_id;
//         $camera->camera_position = $camera_position;
//         $camera->animal_name = $animal_name;
//         $camera->camera_url = $camera_url;

//         //. บันทึก
//         $camera->save();

//         // ตอบกลับ
//         return $this->response->success($camera, 'อัปเดตข้อมูลสำเร็จ', 200);

//     } catch (\Exception $e) {
//         return $this->response->error('เกิดข้อผิดพลาด: ' . $e->getMessage(), 500);
//     }
// }





        // public function UpdateCamera(Request $request,$id)
        // {
        //     $model = new model_user();
        //     $result = $model->Update_user($request,$id);

        //     if ($result === true) {
        //         return $this->response->success([], 'อัพเดทข้อมูลผู้ใช้งานสำเร็จ', 200);
        //     } elseif ($result === 'not_found') {
        //         return $this->response->error('ไม่พบข้อมูลสำหรับ User ID', 404);
        //     } else {
        //         return $this->response->error('เกิดข้อผิดพลาดในการอัพเดทข้อมูล', 500);
        //     }
        // }

        // public function update_password(Request $request,$id)
        // {
        //     if ($id === null) {
        //         return $this->response->error('เกิดข้อผิดพลาด: ไม่พบ User ID', 400);
        //     }
        //     $model = new model_user();
        //     $result = $model->Update_password($id,$request);

        //     if ($result === true) {
        //         return $this->response->success([], 'อัพเดทรหัสผ่านสำเร็จ', 200);
        //     } elseif ($result === 'not_found') {
        //         return $this->response->error('ไม่พบข้อมูลสำหรับ User ID', 404);
        //     } else {
        //         return $this->response->error('เกิดข้อผิดพลาดในการอัพเดทรหัสผ่าน', 500);
        //     }
        // }


        // public function deleteUsers($id)
        // {
        //     $model = new model_user();
        //     $result = $model->Delete_user($id);

        //     if ($result === true) {
        //         return $this->response->success([], 'ลบผู้ใช้งานสำเร็จ', 200);
        //     } elseif ($result === 'not_found') {
        //         return $this->response->error('ไม่พบข้อมูลสำหรับ User ID', 404);
        //     } else {
        //         return $this->response->error('เกิดข้อผิดพลาดในการลบข้อมูล', 500);
        //     }
        // }

}




