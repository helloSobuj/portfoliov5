<?php
// ===== cPanel-এ আপলোডের আগে এগুলো বদলান =====
return [
    // cPanel > MySQL Databases থেকে বানানো ডেটাবেস ও ইউজার
    'db_host' => 'localhost',
    'db_name' => 'cpaneluser_techill',
    'db_user' => 'cpaneluser_techill',
    'db_pass' => 'CHANGE_ME',

    // আপলোড করা ফাইল কোথায় থাকবে। সম্ভব হলে public_html-এর বাইরে রাখুন,
    // যেমন: '/home/cpaneluser/techill_uploads'
    'upload_dir' => __DIR__ . '/uploads',
    'max_upload_mb' => 10,
    'allowed_ext' => ['jpg','jpeg','png','webp','gif','svg','pdf','csv','txt','doc','docx','xls','xlsx','zip'],
];
