<?php

/** @var \Laravel\Lumen\Routing\Router $router */

$router->get('/', function () use ($router) {
    return $router->app->version();
});
$router->options('{any:.*}', function () {
    return response('', 204);
});

$router->group(['prefix' => 'api/v1', 'middleware' => ['auth:api', 'check.blacklist']], function () use ($router) {
    //โฟลเดอร์
    //สร้างโฟลเดอร์
    $router->post('AddFolder', 'FolderCon@AddFolder');

    $router->get('Drive/{id}', 'DriveCon@GetDriveById');
    $router->get('/files/{id}', 'FileCon@show');
    $router->get('/folder/{id}', 'FolderCon@show');
    $router->get('/shared-with-me', 'PermissionsCon@listSharedWithMe');

    $router->post('/permissions/{type}/{id}', 'PermissionsCon@createForItem');

    $router->post('/permissions/{type}/{id}/public', 'PermissionsCon@enablePublic');
    $router->delete('/permissions/{type}/{id}/public', 'PermissionsCon@disablePublic');
    $router->post('/permissions/{permissionId}', 'PermissionsCon@update');

    // สร้าง private link (owner only)
    $router->post('/permissions/{type}/{id}/private-link', 'PermissionsCon@generatePrivateLink');
    $router->get('/drive/{type}/{id}/permissions', 'PermissionsCon@list');
    $router->delete('/permissions/{permissionId}', 'PermissionsCon@destroy');
    
    // ปิด private link (revoke) (owner only)
    $router->delete('/permissions/{type}/{id}/private-link', 'PermissionsCon@revokePrivateLink');

    // ดูผ่านลิงก์ (ทั้ง public & private token)

    $router->post('/uploadFolder', 'FolderCon@uploadFolder');

    $router->get('/download/file/{id}', 'FolderCon@downloadSingle');
    $router->get('/admin/files/{id}/download', 'FolderCon@downloadSingleAdmin');
    $router->get('/download/folder/{id}', 'FolderCon@downloadFolder');
    $router->post('/download/multiple', 'FolderCon@downloadMultiple');
    $router->post('/admin/download-multiple', 'FolderCon@downloadMultipleAdmin');

    $router->get('/shared/file/{permissionId}/download', 'FolderCon@downloadFile');
    $router->get('/shared/folder/{permissionId}/download-zip', 'FolderCon@downloadSharedFolderZip');
    $router->post('/shared/download-multiple', 'FolderCon@downloadSharedMultiple');


    $router->get('/preview/{token}/{fileId}', 'PermissionsCon@previewByToken');

    //อัพโหลดไฟล์

    $router->post('/upload/start', 'UploadSessionsCon@startUpload');
    $router->post('/upload/chunk', 'UploadSessionsCon@uploadChunk');
    $router->post('/upload/cancel', 'UploadSessionsCon@cancelUpload');
    $router->post('/upload/finish', 'UploadSessionsCon@finishUpload');

     //Mydrive
    //แสดงผล
    $router->get('/Drive_Folder/{id}', 'DriveCon@Drive_Folder');
    $router->get('/Mydrive/{id}', 'AuthController@User_Drive');
    $router->get('/User_Drive_Starred/{id}', 'DriveCon@User_Drive_Starred');
    //เพิ่ม
    $router->post('/Upload', 'FileCon@UploadFile');
    $router->post('/Favorite_Folder/{id}', 'DriveCon@Favorite_Folder');
    $router->post('/favoriteFile/{id}', 'DriveCon@favoriteFile');
    //ลบ
    $router->post('/folder/{id}', 'FolderCon@moveToTrash');
    $router->post('/files/{id}', 'FileCon@moveToTrash');
    //แก้ไข
    $router->post('/rename_folder/{id}', 'FolderCon@rename');
    $router->post('/rename_file/{id}', 'FileCon@rename');
    $router->post('/RemoveFavorite/{id}', 'DriveCon@RemoveFavorite');
    $router->post('/RemoveFavoriteFile/{id}', 'DriveCon@RemoveFavoriteFile');

    //ถังขยะ
    //แสดงผล
    $router->get('/Trash/{id}', 'AuthController@User_Drive_Trash');
    //เพิ่ม
    $router->post('/trash/bulk-move', 'TrashCon@bulkMoveToTrash');
    //ลบ
    $router->delete('/delfiles/{id}', 'FileCon@destroyForever');  //
    $router->post('/destroy/{id}', 'FolderCon@destroy'); //
    $router->delete('/emptyTrash', 'TrashCon@emptyTrash'); //
    $router->delete('/trashbulk', 'TrashCon@bulkDestroy');
    //แก้ไข
    $router->post('/restore-folder/{id}', 'FolderCon@restore');
    $router->post('/restore-file/{id}', 'FileCon@restore');

    //เปิดล่าสุด
    $router->get('/OpenFile_view/{id}', 'AuthController@OpenFile_view');
    $router->post('/OpenFile/{id}', 'AuthController@OpenFile');


    $router->get('/users/{driveId}/drive', 'AuthController@AdminUserDrive');
    //LOGOUT
    $router->post('/logout', 'AuthController@logout');

    $router->post('/set-password', 'AuthController@setPassword');

    // แก้ไข user
    $router->post('/users/{id}', 'AuthController@update');

    // ลบ user
    $router->delete('/users/{id}', 'AuthController@destroy');

    //สร้าง user 
    $router->post('/users', 'AuthController@store');

    $router->delete('/admin/drives/{driveId}', 'DriveCon@destroyDriveAndUser');

    $router->get('/admin/drives', 'DriveCon@index');
    $router->get('/admin/drives/{driveId}/contents', 'DriveCon@contents');
    $router->get('/drive/browse', 'DriveCon@browse');


    $router->get('/my-drive/search', 'DriveCon@searchMyDrive');
    $router->get('/admin/drives/{driveId}/search', 'DriveCon@searchAdminDrive');
    $router->get('/admin/drives/{driveId}/browse', 'DriveCon@adminBrowse');


    //ลบข้อมูลadmin
    $router->delete('/admin/folders/{id}/destroy', 'FolderCon@destroyFolderAdmin');
    $router->delete('/admin/files/{id}/destroy-forever', 'FileCon@destroyForeverAdmin');



    });


$router->group(['prefix' => 'api/v1'], function () use ($router) {

    //login 
    $router->post('/login/google', 'AuthController@loginWithGoogle');

//แชร์ PUBLIC
    //UPLOAD PUBLIC
    $router->post('/{token}/upload/start', 'UploadSessionsCon@startPublicUpload');
    $router->post('/public/upload/{uploadId}', ['uses' => 'UploadSessionsCon@uploadPublicChunk']);
    $router->post('/public/upload/finish/{token}/{uploadId}','UploadSessionsCon@finishPublicUpload');

    //สร้างโฟลเดอร Public
    $router->post('/shares/{token}/folders', 'FolderCon@AddFolderByToken');

    //Viewlinktoken PUblic
    $router->get('/preview/{token}/{fileId}', 'PermissionsCon@preview');
    $router->get('/share/{shareToken}/thumbnail/{fileId}', 'PermissionsCon@thumbnail');

    //แชร์วิว ดูได้ทั้ง ส่วนตัว/public
    $router->get('/share/{token}', 'PermissionsCon@viewByToken');
    $router->post('/public/shared/download-multiple', 'FolderCon@downloadPublicSharedMultiple');
    $router->get('/public/shared/file/{permissionId}/download', 'FolderCon@downloadPublicFile');
$router->get(
    '/files/{fileId}/download',
    'FolderCon@downloadByFileId'
);


    $router->get('/users', 'AuthController@listUser');
$router->post('/login', 'AuthController@login');
$router->post('/register', 'AuthController@register');



});
