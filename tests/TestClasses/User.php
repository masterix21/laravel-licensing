<?php

namespace LucaLongo\Licensing\Tests\TestClasses;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $fillable = ['name', 'email'];
    
    protected $table = 'users';
    
    public $timestamps = true;
}