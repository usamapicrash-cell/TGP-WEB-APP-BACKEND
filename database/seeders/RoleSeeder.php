<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('roles')->insert([
        ['name'=>'super_admin','level'=>1],
        ['name'=>'executive','level'=>2],
        ['name'=>'admin','level'=>3],
        ['name'=>'glazier','level'=>4],
        ]);
    }
}
