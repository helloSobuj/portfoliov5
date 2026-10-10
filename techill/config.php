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

    // ভিজিটর কোন দেশ/শহর থেকে এসেছে বের করার উপায়:
    //   'ipapi' = ipapi.co (ফ্রি প্ল্যানে দিনে সীমা আছে, ব্যবহারের আগে ওদের শর্ত দেখে নিন)
    //   'off'   = বন্ধ। Cloudflare ব্যবহার করলে দেশ তখনও CF-IPCountry হেডার থেকে আসবে।
    'geo_lookup' => 'ipapi',
    // IP হ্যাশ করার গোপন লবণ। যেকোনো লম্বা এলোমেলো লেখা দিন, পরে আর বদলাবেন না।
    'geo_salt' => 'CHANGE_ME_TO_A_LONG_RANDOM_STRING',
];
