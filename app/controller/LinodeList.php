<?php

namespace app\controller;

class LinodeList
{
    public static function regions(): array
    {
        return [
            'us-east' => 'Newark, NJ',
            'us-southeast' => 'Atlanta, GA',
            'us-central' => 'Dallas, TX',
            'us-west' => 'Fremont, CA',
            'ca-central' => 'Toronto, CA',
            'eu-west' => 'London, UK',
            'eu-central' => 'Frankfurt, DE',
            'ap-south' => 'Singapore',
            'ap-northeast' => 'Tokyo, JP',
            'ap-west' => 'Mumbai, IN',
            'ap-southeast' => 'Sydney, AU',
        ];
    }

    public static function types(): array
    {
        return [
            'g6-nanode-1' => 'Nanode 1GB / 1 CPU / 25GB',
            'g6-standard-1' => 'Linode 2GB / 1 CPU / 50GB',
            'g6-standard-2' => 'Linode 4GB / 2 CPU / 80GB',
            'g6-standard-4' => 'Linode 8GB / 4 CPU / 160GB',
            'g6-standard-6' => 'Linode 16GB / 6 CPU / 320GB',
            'g6-standard-8' => 'Linode 32GB / 8 CPU / 640GB',
            'g6-standard-16' => 'Linode 64GB / 16 CPU / 1280GB',
        ];
    }

    public static function images(): array
    {
        return [
            'linode/ubuntu24.04' => 'Ubuntu 24.04 LTS',
            'linode/ubuntu22.04' => 'Ubuntu 22.04 LTS',
            'linode/debian12' => 'Debian 12',
            'linode/debian11' => 'Debian 11',
            'linode/almalinux9' => 'AlmaLinux 9',
            'linode/rocky9' => 'Rocky Linux 9',
            'linode/centos-stream9' => 'CentOS Stream 9',
        ];
    }
}
