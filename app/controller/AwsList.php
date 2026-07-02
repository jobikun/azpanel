<?php

namespace app\controller;

class AwsList
{
    public static function instanceSizes()
    {
        return [
            // 免费套餐资格机型（2025-07-15 后注册的新账号只能创建这些，其他机型会报
            // "not eligible for Free Tier"；升级为付费计划后不受限制）
            't3.micro' => '2 vCPU, 1 GiB 内存【免费套餐】',
            't3.small' => '2 vCPU, 2 GiB 内存【免费套餐】',
            't4g.micro' => '2 vCPU, 1 GiB 内存【免费套餐 ARM】',
            't4g.small' => '2 vCPU, 2 GiB 内存【免费套餐 ARM】',
            'c7i-flex.large' => '2 vCPU, 4 GiB 内存【免费套餐】',
            'm7i-flex.large' => '2 vCPU, 8 GiB 内存【免费套餐】',
            // T2 系列 (burstable, x86)
            't2.nano' => '1 vCPU, 0.5 GiB 内存',
            't2.micro' => '1 vCPU, 1 GiB 内存【旧账号免费套餐】',
            't2.small' => '1 vCPU, 2 GiB 内存',
            't2.medium' => '2 vCPU, 4 GiB 内存',
            't2.large' => '2 vCPU, 8 GiB 内存',
            't2.xlarge' => '4 vCPU, 16 GiB 内存',
            't2.2xlarge' => '8 vCPU, 32 GiB 内存',
            // T3 系列 (burstable, x86)
            't3.nano' => '2 vCPU, 0.5 GiB 内存',
            't3.medium' => '2 vCPU, 4 GiB 内存',
            't3.large' => '2 vCPU, 8 GiB 内存',
            't3.xlarge' => '4 vCPU, 16 GiB 内存',
            't3.2xlarge' => '8 vCPU, 32 GiB 内存',
            // T3a 系列 (burstable, AMD)
            't3a.nano' => '2 vCPU, 0.5 GiB 内存',
            't3a.micro' => '2 vCPU, 1 GiB 内存',
            't3a.small' => '2 vCPU, 2 GiB 内存',
            't3a.medium' => '2 vCPU, 4 GiB 内存',
            't3a.large' => '2 vCPU, 8 GiB 内存',
            't3a.xlarge' => '4 vCPU, 16 GiB 内存',
            't3a.2xlarge' => '8 vCPU, 32 GiB 内存',
            // T4g 系列 (burstable, ARM Graviton2)
            't4g.nano' => '2 vCPU, 0.5 GiB 内存',
            't4g.medium' => '2 vCPU, 4 GiB 内存',
            't4g.large' => '2 vCPU, 8 GiB 内存',
            't4g.xlarge' => '4 vCPU, 16 GiB 内存',
            't4g.2xlarge' => '8 vCPU, 32 GiB 内存',
            // C5 系列 (compute optimized, x86)
            'c5.large' => '2 vCPU, 4 GiB 内存',
            'c5.xlarge' => '4 vCPU, 8 GiB 内存',
            'c5.2xlarge' => '8 vCPU, 16 GiB 内存',
            'c5.4xlarge' => '16 vCPU, 32 GiB 内存',
            // C5a 系列 (compute optimized, AMD)
            'c5a.large' => '2 vCPU, 4 GiB 内存',
            'c5a.xlarge' => '4 vCPU, 8 GiB 内存',
            'c5a.2xlarge' => '8 vCPU, 16 GiB 内存',
            'c5a.4xlarge' => '16 vCPU, 32 GiB 内存',
            'c5a.8xlarge' => '32 vCPU, 64 GiB 内存',
            // C5n 系列 (compute optimized, x86 with networking)
            'c5n.large' => '2 vCPU, 5.25 GiB 内存',
            'c5n.xlarge' => '4 vCPU, 10.5 GiB 内存',
            'c5n.2xlarge' => '8 vCPU, 21 GiB 内存',
            'c5n.4xlarge' => '16 vCPU, 42 GiB 内存',
            // C7g 系列 (compute optimized, ARM Graviton3)
            'c7g.medium' => '1 vCPU, 2 GiB 内存',
            'c7g.large' => '2 vCPU, 4 GiB 内存',
            'c7g.xlarge' => '4 vCPU, 8 GiB 内存',
            'c7g.2xlarge' => '8 vCPU, 16 GiB 内存',
            'c7g.4xlarge' => '16 vCPU, 32 GiB 内存',
            // M7g 系列 (general purpose, ARM Graviton3)
            'm7g.medium' => '1 vCPU, 4 GiB 内存',
            'm7g.large' => '2 vCPU, 8 GiB 内存',
            'm7g.xlarge' => '4 vCPU, 16 GiB 内存',
            'm7g.2xlarge' => '8 vCPU, 32 GiB 内存',
            'm7g.4xlarge' => '16 vCPU, 64 GiB 内存',
        ];
    }

    public static function instanceRegion()
    {
        return [
            "us-east-1" => "美国东部（弗吉尼亚北部）",
            "us-east-2" => "美国东部（俄亥俄州）",
            "us-west-1" => "美国西部（加利福尼亚北部）",
            "us-west-2" => "美国西部（俄勒冈州）",
            "af-south-1" => "非洲（开普敦）",
            "ap-east-1" => "亚太地区（香港）",
            "ap-east-2" => "亚太地区（台北）",
            "ap-south-2" => "亚太地区（印度海得拉巴）",
            "ap-south-1" => "亚太地区（孟买）",
            "ap-northeast-3" => "亚太地区（大阪）",
            "ap-northeast-2" => "亚太地区（首尔）",
            "ap-southeast-1" => "亚太地区（新加坡）",
            "ap-southeast-2" => "亚太地区（悉尼）",
            "ap-southeast-3" => "亚太地区（雅加达）",
            "ap-southeast-4" => "亚太地区（墨尔本）",
            "ap-southeast-5" => "亚太地区（马来西亚）",
            "ap-southeast-6" => "亚太地区（新西兰）",
            "ap-southeast-7" => "亚太地区（泰国）",
            "ap-northeast-1" => "亚太地区（东京）",
            "ca-central-1" => "加拿大（中部）",
            "ca-west-1" => "加拿大西部（卡尔加里）",
            "eu-central-1" => "欧洲（法兰克福）",
            "eu-west-1" => "欧洲（爱尔兰）",
            "eu-west-2" => "欧洲（伦敦）",
            "eu-south-1" => "欧洲（米兰）",
            "eu-west-3" => "欧洲（巴黎）",
            "eu-south-2" => "欧洲（西班牙）",
            "eu-north-1" => "欧洲（斯德哥尔摩）",
            "eu-central-2" => "欧洲（苏黎世）",
            "il-central-1" => "以色列（特拉维夫）",
            "mx-central-1" => "墨西哥（中部）",
            "me-south-1" => "中东（巴林）",
            "me-central-1" => "中东（阿联酋）",
            "sa-east-1" => "南美洲（巴西圣保罗）",
        ];
    }

    public static function instanceImage()
    {
        // imageName 为 x86(amd64) 机型使用的 AMI，imageNameArm 为 t4g/c7g/m7g 等
        // ARM(Graviton) 机型使用的 AMI；没有 imageNameArm 的镜像不支持 ARM 机型
        return [
            'debian-11' => [
                'imageOwner' => '136693071363',
                'imageName' => 'debian-11-amd64-*',
                'imageNameArm' => 'debian-11-arm64-*',
            ],
            'debian-12' => [
                'imageOwner' => '136693071363',
                'imageName' => 'debian-12-amd64-*',
                'imageNameArm' => 'debian-12-arm64-*',
            ],
            'ubuntu-22.04' => [
                'imageOwner' => '099720109477',
                'imageName' => 'ubuntu/images/hvm-ssd/ubuntu-jammy-22.04-amd64-server-*',
                'imageNameArm' => 'ubuntu/images/hvm-ssd/ubuntu-jammy-22.04-arm64-server-*',
            ],
            'ubuntu-24.04' => [
                'imageOwner' => '099720109477',
                'imageName' => 'ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-amd64-server-*',
                'imageNameArm' => 'ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-arm64-server-*',
            ],
            'al2023' => [
                'imageOwner' => '137112412989',
                'imageName' => 'al2023-ami-*-kernel-*-x86_64',
                'imageNameArm' => 'al2023-ami-*-kernel-*-arm64',
            ],
            'Windows_Server_2022' => [
                'imageOwner' => '801119661308',
                'imageName' => 'Windows_Server-2022-English-Full-Base-*',
            ],
            'Windows_Server_2025' => [
                'imageOwner' => '801119661308',
                'imageName' => 'Windows_Server-2025-English-Full-Base-*',
            ],
        ];
    }

    /**
     * 判断机型是否为 ARM(Graviton) 架构，如 t4g / c7g / m7g / c7gn / r8g 等。
     */
    public static function isArmInstanceType(string $size): bool
    {
        $family = explode('.', $size)[0];

        return (bool) preg_match('/\d+g/', $family);
    }
}
