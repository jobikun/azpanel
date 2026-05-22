<?php

namespace app\controller;

use app\BaseController;
use Aws\ServiceQuotas\ServiceQuotasClient;

class AwsApi extends BaseController
{
    public static function normalizeInstanceAction(string $action): string
    {
        $actions = [
            'start' => 'startInstances',
            'startInstances' => 'startInstances',
            'stop' => 'stopInstances',
            'stopInstances' => 'stopInstances',
            'restart' => 'rebootInstances',
            'reboot' => 'rebootInstances',
            'rebootInstances' => 'rebootInstances',
            'terminate' => 'terminateInstances',
            'terminateInstances' => 'terminateInstances',
        ];

        if (! isset($actions[$action])) {
            throw new \InvalidArgumentException('Unsupported AWS EC2 action: ' . $action);
        }

        return $actions[$action];
    }

    public static function manageInstances(object $session, string $action, array $instance_ids): array
    {
        if ($instance_ids === []) {
            throw new \InvalidArgumentException('No AWS EC2 instances selected.');
        }

        $action = self::normalizeInstanceAction($action);
        return $session->$action([
            'InstanceIds' => $instance_ids,
        ])->toArray();
    }

    public static function getQuota(string $region, string $ak, string $sk, ?string $proxy_url = null)
    {
        try {
            $request_params = [
                'region' => $region,
                'version' => 'latest',
                'credentials' => [
                    'key' => $ak,
                    'secret' => $sk,
                ],
            ];

            if ($proxy_url !== null) {
                $request_params['http'] = ProxyController::createAwsHttpOptions($proxy_url);
            }

            $client = new ServiceQuotasClient($request_params);

            $result = $client->getServiceQuota([
                'QuotaCode' => 'L-1216C47A',
                'ServiceCode' => 'ec2',
            ]);
        } catch (\Exception $e) {
            return 'null';
        }

        return (int) $result['Quota']['Value'];
    }

    public static function createAWSClient(
        string $region,
        string $access_key,
        string $secret_key,
        bool $use_proxy = false,
        string $mode = 'quota',
        ?string $proxy_url = null
    ) {
        $request_params = [
            'region' => $region,
            'version' => 'latest',
            'credentials' => [
                'key' => $access_key,
                'secret' => $secret_key,
            ],
        ];
        if ($use_proxy && $proxy_url !== null) {
            $request_params['http'] = ProxyController::createAwsHttpOptions($proxy_url);
        }
        if ($mode === 'quota') {
            $client = new ServiceQuotasClient($request_params);
        } else {
            $client = new \Aws\Ec2\Ec2Client($request_params);
        }

        return $client;
    }

    public static function getRegionQuota(object $session, string $region)
    {
        $result = $session->getServiceQuota([
            'QuotaCode' => 'L-1216C47A',
            'ServiceCode' => 'ec2',
        ]);
        return intval($result['Quota']['Value']) ?? null;
    }

    public static function createVpc(object $session): string
    {
        $result = $session->createVpc([
            'CidrBlock' => '172.31.0.0/16',
            'AmazonProvidedIpv6CidrBlock' => true,
        ]);
        return $result['Vpc']['VpcId'];
    }

    public static function createSubnet(object $session, string $vpc_id): string
    {
        $subet_cidr = ['172.31.0.0/16', '172.31.0.0/20', '172.31.32.0/20'];
        $result = $session->createSubnet([
            'VpcId' => $vpc_id,
            'CidrBlock' => $subet_cidr[0],
        ]);
        return $result['Subnet']['SubnetId'];
    }

    public static function describeImages(object $session, string $imageName, string $imageOwner): string
    {
        $result = $session->describeImages([
            'Filters' => [
                [
                    'Name' => 'name',
                    'Values' => [
                        $imageName,
                    ],
                ],
            ],
            'Owners' => [
                $imageOwner,
            ],
        ]);
        $images = $result['Images'] ?? [];
        if ($images === []) {
            throw new \RuntimeException('No AWS AMI found for image "' . $imageName . '" with owner "' . $imageOwner . '" in the selected region.');
        }

        usort($images, static fn ($a, $b) => strcmp($b['CreationDate'] ?? '', $a['CreationDate'] ?? ''));

        return $images[0]['ImageId'];
    }

    public static function createKeyPair(object $session, string $name): void
    {
        $session->createKeyPair([
            'KeyName' => $name,
        ]);
    }

    public static function createSecurityGroup(object $session, string $name, ?string $vpc_id = null): string
    {
        $params = [
            'Description' => $name,
            'GroupName' => $name,
        ];
        if (isset($vpc_id)) {
            $params['VpcId'] = $vpc_id;
        }
        $result = $session->createSecurityGroup($params);
        return $result['GroupId'];
    }

    public static function authorizeSecurityGroupIngress(object $session, string $groupId, array $IpPermissions): void
    {
        $session->authorizeSecurityGroupIngress([
            'GroupId' => $groupId,
            'IpPermissions' => $IpPermissions,
        ]);
    }

    public static function runInstances(
        object $session,
        string $imageId,
        array $params,
        string $groupId,
        ?string $subnet_id = null
    ): string {
        $params = [
            'BlockDeviceMappings' => [
                [
                    'DeviceName' => '/dev/xvda',
                    'Ebs' => [
                        'VolumeSize' => $params['disk_size'],
                    ],
                ],
            ],
            'ImageId' => $imageId,
            'InstanceType' => $params['size'],
            'KeyName' => $params['name'],
            'MinCount' => 1,
            'MaxCount' => 1,
            'SecurityGroupIds' => [
                $groupId,
            ],
            'UserData' => base64_encode($params['userDataRaw']),
            'TagSpecifications' => [
                [
                    'ResourceType' => 'instance',
                    'Tags' => [
                        [
                            'Key' => 'Name',
                            'Value' => $params['name'],
                        ],
                    ],
                ],
            ],
        ];
        if (isset($subnet_id)) {
            $params['SubnetId'] = $subnet_id;
        }
        $result = $session->runInstances($params);
        return $result['Instances'][0]['InstanceId'];
    }

    public static function allocateAddress(object $session): array
    {
        $result = $session->allocateAddress([
            'Domain' => 'vpc',
        ]);
        return [
            $result['PublicIp'],
            $result['AllocationId'],
        ];
    }

    public static function waitForInstanceToRun(object $session, string $InstanceId): void
    {
        while (true) {
            $result = $session->describeInstances([
                'Filters' => [
                    [
                        'Name' => 'instance-id',
                        'Values' => [
                            $InstanceId,
                        ],
                    ],
                ],
            ]);
            if ($result['Reservations'][0]['Instances'][0]['State']['Name'] !== 'pending') {
                break;
            }
        }
    }

    public static function createInternetGateway(object $session): string
    {
        $result = $session->createInternetGateway();
        return $result['InternetGateway']['InternetGatewayId'];
    }

    public static function attachInternetGateway(object $session, string $internetGatewayId, string $vpc_id): void
    {
        $session->attachInternetGateway([
            'InternetGatewayId' => $internetGatewayId,
            'VpcId' => $vpc_id,
        ]);
    }

    public static function modifyVpcAttribute(object $session, string $vpc_id): void
    {
        $session->modifyVpcAttribute([
            'VpcId' => $vpc_id,
            'EnableDnsHostnames' => [
                'Value' => true,
            ],
        ]);
    }

    public static function associateAddress(object $session, string $AllocationId, string $subnet_id, string $InstanceId): void
    {
        $session->associateAddress([
            'AllocationId' => $AllocationId,
            'SubnetId' => $subnet_id,
            'InstanceId' => $InstanceId,
        ]);
    }

    public static function getNetworkInterfaceId(object $session, string $InstanceId): string
    {
        $result = $session->describeInstances([
            'Filters' => [
                [
                    'Name' => 'instance-id',
                    'Values' => [
                        $InstanceId,
                    ],
                ],
            ],
        ]);
        return $result['Reservations'][0]['Instances'][0]['NetworkInterfaces'][0]['NetworkInterfaceId'];
    }

    public static function getIpv6CidrBlock(object $session, string $vpc_id): string
    {
        $result = $session->describeVpcs([
            'VpcIds' => [
                $vpc_id,
            ],
        ]);
        foreach ($result['Vpcs'][0]['Ipv6CidrBlockAssociationSet'] ?? [] as $association) {
            if (($association['Ipv6CidrBlockState']['State'] ?? null) === 'associated') {
                return $association['Ipv6CidrBlock'];
            }
        }

        throw new \RuntimeException('No associated IPv6 CIDR block found for VPC ' . $vpc_id . '.');
    }

    public static function ensureVpcIpv6CidrBlock(object $session, string $vpc_id): string
    {
        try {
            return self::getIpv6CidrBlock($session, $vpc_id);
        } catch (\RuntimeException $e) {
            $result = $session->describeVpcs([
                'VpcIds' => [
                    $vpc_id,
                ],
            ]);
            $has_pending_association = false;
            foreach ($result['Vpcs'][0]['Ipv6CidrBlockAssociationSet'] ?? [] as $association) {
                if (($association['Ipv6CidrBlockState']['State'] ?? null) !== 'disassociated') {
                    $has_pending_association = true;
                    break;
                }
            }

            if (!$has_pending_association) {
                $session->associateVpcCidrBlock([
                    'AmazonProvidedIpv6CidrBlock' => true,
                    'VpcId' => $vpc_id,
                ]);
            }
        }

        for ($i = 0; $i < 30; $i++) {
            try {
                return self::getIpv6CidrBlock($session, $vpc_id);
            } catch (\RuntimeException $e) {
                sleep(2);
            }
        }

        throw new \RuntimeException('Timed out waiting for VPC IPv6 CIDR block association.');
    }

    public static function calculatingIpv6Subnets(string $ipv6_cidr): string
    {
        $subnets_64 = [];
        $networks = \IPTools\Network::parse($ipv6_cidr)->moveTo('64');
        foreach ($networks as $network) {
            $subnets_64[] = (string) $network;
        }
        return $subnets_64[array_rand($subnets_64)];
    }

    public static function getAvailableIpv6SubnetCidr(object $session, string $vpc_id, string $ipv6_cidr): string
    {
        $result = $session->describeSubnets([
            'Filters' => [
                [
                    'Name' => 'vpc-id',
                    'Values' => [
                        $vpc_id,
                    ],
                ],
            ],
        ]);

        $used = [];
        foreach ($result['Subnets'] ?? [] as $subnet) {
            foreach ($subnet['Ipv6CidrBlockAssociationSet'] ?? [] as $association) {
                if (($association['Ipv6CidrBlockState']['State'] ?? null) !== 'disassociated') {
                    $used[$association['Ipv6CidrBlock']] = true;
                }
            }
        }

        $networks = \IPTools\Network::parse($ipv6_cidr)->moveTo('64');
        foreach ($networks as $network) {
            $cidr = (string) $network;
            if (!isset($used[$cidr])) {
                return $cidr;
            }
        }

        throw new \RuntimeException('No available IPv6 /64 subnet CIDR found in VPC ' . $vpc_id . '.');
    }

    public static function getSubnetIpv6CidrBlock(object $session, string $subnet_id): ?string
    {
        $result = $session->describeSubnets([
            'SubnetIds' => [
                $subnet_id,
            ],
        ]);

        foreach ($result['Subnets'][0]['Ipv6CidrBlockAssociationSet'] ?? [] as $association) {
            if (($association['Ipv6CidrBlockState']['State'] ?? null) === 'associated') {
                return $association['Ipv6CidrBlock'];
            }
        }

        return null;
    }

    public static function associateSubnetCidrBlock(object $session, string $use_ipv6_subnet, string $subnet_id): void
    {
        $session->associateSubnetCidrBlock([
            'Ipv6CidrBlock' => $use_ipv6_subnet,
            'SubnetId' => $subnet_id,
        ]);
    }

    public static function ensureSubnetIpv6CidrBlock(object $session, string $vpc_id, string $subnet_id): string
    {
        $subnet_cidr = self::getSubnetIpv6CidrBlock($session, $subnet_id);
        if ($subnet_cidr !== null) {
            return $subnet_cidr;
        }

        $result = $session->describeSubnets([
            'SubnetIds' => [
                $subnet_id,
            ],
        ]);
        $has_pending_association = false;
        foreach ($result['Subnets'][0]['Ipv6CidrBlockAssociationSet'] ?? [] as $association) {
            if (($association['Ipv6CidrBlockState']['State'] ?? null) !== 'disassociated') {
                $has_pending_association = true;
                break;
            }
        }

        if (!$has_pending_association) {
            $vpc_cidr = self::ensureVpcIpv6CidrBlock($session, $vpc_id);
            self::associateSubnetCidrBlock($session, self::getAvailableIpv6SubnetCidr($session, $vpc_id, $vpc_cidr), $subnet_id);
        }

        for ($i = 0; $i < 30; $i++) {
            $subnet_cidr = self::getSubnetIpv6CidrBlock($session, $subnet_id);
            if ($subnet_cidr !== null) {
                return $subnet_cidr;
            }
            sleep(2);
        }

        throw new \RuntimeException('Timed out waiting for subnet IPv6 CIDR block association.');
    }

    public static function assignIpv6Addresses(object $session, string $NetworkInterfaceId): string
    {
        $result = $session->assignIpv6Addresses([
            'NetworkInterfaceId' => $NetworkInterfaceId,
            'Ipv6AddressCount' => 1,
        ]);
        return $result['AssignedIpv6Addresses'][0];
    }

    public static function ensureRouteTableIpv6Route(object $session, string $vpc_id, string $subnet_id): void
    {
        $route_table_id = self::getRouteTableIdForSubnet($session, $vpc_id, $subnet_id);

        $route_table = $session->describeRouteTables([
            'RouteTableIds' => [
                $route_table_id,
            ],
        ]);
        foreach ($route_table['RouteTables'][0]['Routes'] ?? [] as $route) {
            if (($route['DestinationIpv6CidrBlock'] ?? null) === '::/0') {
                return;
            }
        }

        $internet_gateway_id = self::describeInternetGateways($session, $vpc_id);
        $session->createRoute([
            'DestinationIpv6CidrBlock' => '::/0',
            'GatewayId' => $internet_gateway_id,
            'RouteTableId' => $route_table_id,
        ]);
    }

    public static function getRouteTableIdForSubnet(object $session, string $vpc_id, string $subnet_id): string
    {
        $result = $session->describeRouteTables([
            'Filters' => [
                [
                    'Name' => 'association.subnet-id',
                    'Values' => [
                        $subnet_id,
                    ],
                ],
            ],
        ]);
        if (($result['RouteTables'] ?? []) !== []) {
            return $result['RouteTables'][0]['RouteTableId'];
        }

        $result = $session->describeRouteTables([
            'Filters' => [
                [
                    'Name' => 'vpc-id',
                    'Values' => [
                        $vpc_id,
                    ],
                ],
                [
                    'Name' => 'association.main',
                    'Values' => [
                        'true',
                    ],
                ],
            ],
        ]);
        if (($result['RouteTables'] ?? []) !== []) {
            return $result['RouteTables'][0]['RouteTableId'];
        }

        throw new \RuntimeException('No route table found for subnet ' . $subnet_id . '.');
    }

    public static function describeRouteTables(object $session, string $vpc_id): string
    {
        $result = $session->describeRouteTables([
            'Filters' => [
                [
                    'Name' => 'vpc-id',
                    'Values' => [
                        $vpc_id,
                    ],
                ],
            ],
        ]);
        return $result['RouteTables'][0]['Associations'][0]['RouteTableId'];
    }

    public static function describeInternetGateways(object $session, string $vpc_id): string
    {
        $result = $session->describeInternetGateways([
            'Filters' => [
                [
                    'Name' => 'attachment.vpc-id',
                    'Values' => [
                        $vpc_id,
                    ],
                ],
            ],
        ]);
        return $result['InternetGateways'][0]['InternetGatewayId'];
    }

    public static function createRoute(object $session, string $InternetGatewayId, string $RouteTableId): void
    {
        $session->createRoute([
            'DestinationIpv6CidrBlock' => '::/0',
            'GatewayId' => $InternetGatewayId,
            'RouteTableId' => $RouteTableId,
        ]);
        $session->createRoute([
            'DestinationCidrBlock' => '0.0.0.0/0',
            'GatewayId' => $InternetGatewayId,
            'RouteTableId' => $RouteTableId,
        ]);
    }

    public static function countRegionVpc(object $session, string $region): int
    {
        $result = $session->describeVpcs();
        return count($result['Vpcs']);
    }

    public static function getInstancePublicIpv4(object $session, string $instance_id): string
    {
        while (true) {
            $result = $session->describeInstances([
                'Filters' => [
                    [
                        'Name' => 'instance-id',
                        'Values' => [
                            $instance_id,
                        ],
                    ],
                ],
            ]);
            if (isset($result['Reservations'][0]['Instances'][0]['NetworkInterfaces'][0]['Association']['PublicIp'])) {
                break;
            }
            sleep(2);
        }
        return $result['Reservations'][0]['Instances'][0]['NetworkInterfaces'][0]['Association']['PublicIp'];
    }

    public static function createIpv6EC2(object $client, array $params): array
    {
        $name = $params['name'];
        $vpc_id = self::createVpc($client);
        $subnet_id = self::createSubnet($client, $vpc_id);
        $imageId = self::describeImages($client, $params['imageName'], $params['imageOwner']);
        self::createKeyPair($client, $name);
        $groupId = self::createSecurityGroup($client, $name, $vpc_id);
        self::authorizeSecurityGroupIngress($client, $groupId, $params['IpPermissions']);
        $instance_id = self::runInstances($client, $imageId, $params, $groupId, $subnet_id);
        $public_ip = self::allocateAddress($client); // array return
        self::waitForInstanceToRun($client, $instance_id);
        $internetGatewayId = self::createInternetGateway($client);
        self::attachInternetGateway($client, $internetGatewayId, $vpc_id);
        self::modifyVpcAttribute($client, $vpc_id);
        self::associateAddress($client, $public_ip[1], $subnet_id, $instance_id);
        $NetworkInterfaceId = self::getNetworkInterfaceId($client, $instance_id);
        $ipv6_cidr = self::getIpv6CidrBlock($client, $vpc_id);
        $use_ipv6_subnet = self::calculatingIpv6Subnets($ipv6_cidr);
        self::associateSubnetCidrBlock($client, $use_ipv6_subnet, $subnet_id);
        $ipv6_addr = self::assignIpv6Addresses($client, $NetworkInterfaceId); // return ipv6 address
        $RouteTableId = self::describeRouteTables($client, $vpc_id);
        $InternetGatewayId = self::describeInternetGateways($client, $vpc_id);
        self::createRoute($client, $InternetGatewayId, $RouteTableId);

        return [
            'vpc_id' => $vpc_id,
            'subnet_id' => $subnet_id,
            'instance_id' => $instance_id,
            'public_ip' => $public_ip[0],
            'ipv6_cidr' => $ipv6_cidr,
            'ipv6_addr' => $ipv6_addr,
        ];
    }

    public static function createOnlyIpv4EC2(object $client, array $params): array
    {
        $name = $params['name'];
        $imageId = self::describeImages($client, $params['imageName'], $params['imageOwner']);
        self::createKeyPair($client, $name);
        $groupId = self::createSecurityGroup($client, $name);
        self::authorizeSecurityGroupIngress($client, $groupId, $params['IpPermissions']);
        $instance_id = self::runInstances($client, $imageId, $params, $groupId);
        $public_ip = self::getInstancePublicIpv4($client, $instance_id);

        return [
            'instance_id' => $instance_id,
            'public_ip' => $public_ip,
        ];
    }
}
