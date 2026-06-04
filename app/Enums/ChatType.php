<?php

namespace App\Enums;

enum ChatType: string
{
    case Public = 'public';
    case Private = 'private';
    case Group = 'group';
}
