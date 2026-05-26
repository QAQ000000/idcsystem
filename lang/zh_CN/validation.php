<?php

return [
    'required' => ':attribute 不能为空。',
    'email' => ':attribute 必须是有效的邮箱地址。',
    'integer' => ':attribute 必须是整数。',
    'numeric' => ':attribute 必须是数字。',
    'max' => [
        'string' => ':attribute 不能超过 :max 个字符。',
        'numeric' => ':attribute 不能大于 :max。',
    ],
    'min' => [
        'string' => ':attribute 不能少于 :min 个字符。',
        'numeric' => ':attribute 不能小于 :min。',
    ],
    'in' => '所选 :attribute 无效。',
    'exists' => '所选 :attribute 不存在。',
    'confirmed' => ':attribute 确认不一致。',
];
