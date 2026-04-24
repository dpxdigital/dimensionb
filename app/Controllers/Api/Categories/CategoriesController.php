<?php

namespace App\Controllers\Api\Categories;

use App\Controllers\Api\BaseApiController;
use App\Models\CategoryModel;
use CodeIgniter\HTTP\ResponseInterface;

class CategoriesController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $categories = (new CategoryModel())->allOrdered();
        return $this->success($categories);
    }
}
