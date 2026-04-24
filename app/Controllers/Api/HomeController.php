<?php

namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;

class HomeController extends ResourceController
{
    public function index()
    {
        return $this->respond([
            'status'  => 'success',
            'message' => 'Dimensions 2.0 API v1',
            'data'    => ['version' => '1.0.0'],
        ]);
    }
}
