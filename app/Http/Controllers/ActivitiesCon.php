<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Response;
use App\Models\Activities;
use Illuminate\Support\Facades\Hash;
class ActivitiesCon extends Controller
{
    protected $response;

    public function __construct()
    {
        $this->response = new Response();
    }


}




