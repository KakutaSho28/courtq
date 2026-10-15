<?php

namespace App\Enums;

/** users.role。サーバー側でだけ付与する（クライアントからは受け取らない） */
enum UserRole: string
{
    case User = 'user';
    case Staff = 'staff';
    case Admin = 'admin';
}
