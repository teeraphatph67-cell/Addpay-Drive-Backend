<?php


namespace App\Models;


use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Lumen\Auth\Authorizable;
class User extends Model implements AuthenticatableContract, JWTSubject
{
    use Authenticatable;

    protected $fillable = [
        'name',
        'email',
        'username',
        'avatar_url',
        'password',
        'quota_total_mb',
        'quota_used_mb',
        'status'
    ];
    protected $hidden = ['password'];

    // Implement สำหรับ JWT
    public function getJWTIdentifier() {
        return $this->getKey();
    }

    public function getJWTCustomClaims() {
        return [];
    }

    public function drive()
{
    return $this->hasOne(\App\Models\Drive::class);
}

 public function UserOne()
    {
        return $this->belongsTo(Drive::class, 'user_id');
    }


    public function driveUser()
{
    return $this->hasOne(\App\Models\Drive::class, 'user_id');
}

}