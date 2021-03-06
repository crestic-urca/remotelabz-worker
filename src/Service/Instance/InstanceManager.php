<?php

namespace App\Service\Instance;

use App\Bridge\Network\IPTables\IPTables;
use App\Bridge\Network\IPTables\Rule;
use App\Bridge\Network\IPTools;
use App\Bridge\Network\OVS;
use App\Exception\BadDescriptorException;
use ErrorException;
use App\Service\Instance\LogDispatcher;
use Psr\Log\LoggerInterface;
use Remotelabz\Message\Message\InstanceLogMessage;
use Remotelabz\Message\Message\InstanceStateMessage;
use Remotelabz\NetworkBundle\Entity\Network;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class InstanceManager extends AbstractController
{
    protected $kernel;
    protected $logger;
    protected $params;

    public function __construct(
        LogDispatcher $logger,
        KernelInterface $kernel,
        ParameterBagInterface $params
    ) {
        $this->kernel = $kernel;
        $this->logger = $logger;
        $this->params = $params;
    }

    public function createLabInstance(string $descriptor, string $uuid) {
        /** @var array $labInstance */
        $labInstance = json_decode($descriptor, true, 4096, JSON_OBJECT_AS_ARRAY);

        if (!is_array($labInstance)) {
            // invalid json
            $this->logger->error("Invalid JSON was provided!", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $labInstance]);

            throw new BadDescriptorException($labInstance);
        }

        try {
            $bridgeName = $labInstance['bridgeName'];
        } catch (ErrorException $e) {
            $this->logger->error("Bridge name is missing!", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $labInstance]);
            throw new BadDescriptorException($labInstance, "", 0, $e);
        }

        // OVS

        if (!IPTools::networkInterfaceExists($bridgeName)) {
            OVS::bridgeAdd($bridgeName, true);
            $this->logger->info("Bridge doesn't exists. Creating bridge for lab instance.", InstanceLogMessage::SCOPE_PRIVATE, [
                'bridgeName' => $bridgeName,
                'instance' => $labInstance['uuid']
            ]);
        } else {
            $this->logger->debug("Bridge already exists. Skipping bridge creation for lab instance.", InstanceLogMessage::SCOPE_PRIVATE, [
                'bridgeName' => $bridgeName,
                'instance' => $labInstance['uuid']
            ]);
        }
    }

    public function deleteLabInstance(string $descriptor, string $uuid) {
        /** @var array $labInstance */
        $labInstance = json_decode($descriptor, true, 4096, JSON_OBJECT_AS_ARRAY);

        if (!is_array($labInstance)) {
            // invalid json
            $this->logger->error("Invalid JSON was provided!", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $labInstance]);

            throw new BadDescriptorException($labInstance);
        }

        try {
            $bridgeName = $labInstance['bridgeName'];
        } catch (ErrorException $e) {
            $this->logger->error("Bridge name is missing!", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $labInstance]);
            throw new BadDescriptorException($labInstance, "", 0, $e);
        }

        // OVS

        OVS::bridgeDelete($bridgeName, true);

        try {
            $labUser = $labInstance['owner']['uuid'];
            $ownedBy = $labInstance['ownedBy'];
            $labInstanceUuid = $labInstance['uuid'];
        } catch (ErrorException $e) {
            throw new BadDescriptorException($labInstance, "", 0, $e);
        }

        $instancePath = $this->kernel->getProjectDir() . "/instances";
        $instancePath .= ($ownedBy === 'group') ? '/group' : '/user';
        $instancePath .= '/' . $labUser;
        $instancePath .= '/' . $labInstanceUuid;

        $filesystem = new Filesystem();
        $filesystem->remove($instancePath);
    }

    /**
     * Start an instance described by JSON descriptor for device instance specified by UUID.
     *
     * @param string $descriptor JSON representation of a lab instance.
     * @param string $uuid UUID of the device instance to start.
     * @throws ProcessFailedException When a process failed to run.
     * @return void
     */
    public function startDeviceInstance(string $descriptor, string $uuid) {
        # TODO: send lab logs
        /** @var array $labInstance */

        $this->logger->setUuid($uuid);
        $labInstance = json_decode($descriptor, true, 4096, JSON_OBJECT_AS_ARRAY);

        if (!is_array($labInstance)) {
            // invalid json
            $this->logger->error("Invalid JSON was provided!", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $labInstance]);

            throw new BadDescriptorException($labInstance);
        }

        try {
            $bridgeName = $labInstance['bridgeName'];
        } catch (ErrorException $e) {
            $this->logger->error("Bridge name is missing!", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $labInstance]);
            throw new BadDescriptorException($labInstance, "", 0, $e);
        }

        // OVS

        if (!IPTools::networkInterfaceExists($bridgeName)) {
            OVS::bridgeAdd($bridgeName, true);
            $this->logger->info("Bridge doesn't exists. Creating bridge for lab instance.", InstanceLogMessage::SCOPE_PUBLIC, [
                'bridgeName' => $bridgeName,
                'instance' => $labInstance['uuid']
            ]);
        } else {
            $this->logger->debug("Bridge already exists. Skipping bridge creation for lab instance.", InstanceLogMessage::SCOPE_PUBLIC, [
                'bridgeName' => $bridgeName,
                'instance' => $labInstance['uuid']
            ]);
        }

        // TODO: add command sudo ip addr add $(echo ${NETWORK_LAB} | cut -d. -f1-3).1/24 dev ${BRIDGE_NAME}
        // $labNetwork = explode('.', $_ENV['LAB_NETWORK']);
        $labNetwork = new Network($labInstance['network']['ip']['addr'], $labInstance['network']['netmask']['addr']);  
        $gateway = $labNetwork->getLastAddress();
        
        if (!IPTools::networkIPExists($bridgeName, $gateway)) {
            $this->logger->debug("Adding IP address to OVS bridge.", InstanceLogMessage::SCOPE_PRIVATE, [
                'bridge' => $bridgeName,
                'ip' => $gateway
            ]);
            IPTools::addrAdd($bridgeName, $gateway."/".$labInstance['network']['netmask']['addr']);
        }
        $this->logger->debug("OVS bridge set up.", InstanceLogMessage::SCOPE_PRIVATE, [
            'bridge' => $bridgeName
        ]);
        IPTools::linkSet($bridgeName, IPTools::LINK_SET_UP);

        // Network interfaces

        $deviceInstance = array_filter($labInstance["deviceInstances"], function ($deviceInstance) use ($uuid) {
            return ($deviceInstance['uuid'] == $uuid && $deviceInstance['state'] != 'started');
        });

        if (!count($deviceInstance)) {
            $this->logger->info("Device instance is already started. Aborting.", InstanceLogMessage::SCOPE_PUBLIC, [
                'uuid' => $deviceInstance['uuid']
            ]);
            // instance is already started or whatever
            return;
        } else {
            $deviceIndex = array_key_first($deviceInstance);
            $deviceInstance = $deviceInstance[$deviceIndex];
        }

        try {
            $labUser = $labInstance['owner']['uuid'];
            $ownedBy = $labInstance['ownedBy'];
            $labInstanceUuid = $labInstance['uuid'];
            $img = [
                "source" => $deviceInstance['device']['operatingSystem']['image']
            ];
        } catch (ErrorException $e) {
            throw new BadDescriptorException($labInstance, "", 0, $e);
        }

        $filesystem = new Filesystem();

        $instancePath = $this->kernel->getProjectDir() . "/instances";
        $instancePath .= ($ownedBy === 'group') ? '/group' : '/user';
        $instancePath .= '/' . $labUser;
        $instancePath .= '/' . $labInstanceUuid;
        $instancePath .= '/' . $uuid;

        $filesystem->mkdir($instancePath);
        if (!$filesystem->exists($this->kernel->getProjectDir() . "/images")) {
            $filesystem->mkdir($this->kernel->getProjectDir() . "/images");
        }

        if (filter_var($img["source"], FILTER_VALIDATE_URL)) {
            if (!$filesystem->exists($this->kernel->getProjectDir() . "/images/" . basename($img["source"]))) {
                $this->logger->info('Remote image is not in cache. Downloading...', InstanceLogMessage::SCOPE_PUBLIC, [
                    "image" => $img['source']
                ]);
                // check image size
                $headers = get_headers($img["source"], 1);
                $headers = array_change_key_case($headers);
                $fileSize = 0.0;
                if(isset($headers['content-length'])){
                    $fileSize = (float) $headers['content-length'];
                }

                $this->logger->info('Image size is '.round($fileSize*1e-6, 2).'MB.', InstanceLogMessage::SCOPE_PUBLIC, [
                    "image" => $img['source']
                ]);
                $chunkSize = 1024 * 1024;
                $fd = fopen($img["source"], 'rb');
                $downloaded = 0.0;
                $lastNotification = 0.0;

                while (!feof($fd)) {
                    $buffer = fread($fd, $chunkSize);
                    file_put_contents($this->kernel->getProjectDir() . "/images/" . basename($img["source"]), $buffer, FILE_APPEND);
                    if (ob_get_level() > 0)
                        ob_flush();
                    flush();
                    clearstatcache();
                    $downloaded = (float) filesize($this->kernel->getProjectDir() . "/images/" . basename($img["source"]));
                    $downloadedPercent = floor(($downloaded/$fileSize) * 100.0);
                    if ($downloadedPercent - $lastNotification >= 5.0) {
                        $this->logger->info('Downloading image... '.$downloadedPercent.'%', InstanceLogMessage::SCOPE_PUBLIC, [
                            "image" => $img['source']
                        ]);
                        $lastNotification = $downloadedPercent;
                    }
                }

                $this->logger->info('Image download complete.', InstanceLogMessage::SCOPE_PUBLIC, [
                    "image" => $img['source']
                ]);
                fclose($fd);
            }
        }

        $img['destination'] = $instancePath . '/' . basename($img['source']);
        $img['source'] = $this->kernel->getProjectDir() . "/images/" . basename($img['source']);

        if (!$filesystem->exists($img['destination'])) {
            $this->logger->info('VM image doesn\'t exist. Creating new image from source...', InstanceLogMessage::SCOPE_PUBLIC, [
                'source' => $img['source']
            ]);
            $process = new Process([ 'qemu-img', 'create', '-f', 'qcow2', '-b', $img['source'], $img['destination']]);
            $process->mustRun();
            $this->logger->info('VM image created.', InstanceLogMessage::SCOPE_PUBLIC, [
                'path' => $img['destination']
            ]);
        }

        $parameters = [
            'system' => [
                '-m',
                $deviceInstance['device']['flavor']['memory'],
                '-hda',
                $img['destination']
            ],
            'network' => [],
            'access' => [],
            'local' => []
        ];

        foreach($deviceInstance['networkInterfaceInstances'] as $nic) {
            $nicTemplate = $nic['networkInterface'];
            $nicName = substr(str_replace(' ', '_', $nicTemplate['name']), 0, 6) . '-' . substr($nic['uuid'], 0, 8);
            $nicVlan = null;
            if (array_key_exists('vlan', $nicTemplate) && $nicTemplate['vlan'] > 0) {
                $nicVlan = $nicTemplate['vlan'];
            }

            if (!IPTools::networkInterfaceExists($nicName)) {
                IPTools::tuntapAdd($nicName, IPTools::TUNTAP_MODE_TAP);
                $this->logger->debug("Network interface created.", InstanceLogMessage::SCOPE_PRIVATE, [
                    'NIC' => $nicName
                ]);
            }

            if (!OVS::ovsPortExists($bridgeName, $nicName)) {
                OVS::portAdd($bridgeName, $nicName, true, ($nicVlan !== null ? 'tag='.$nicVlan : ''));
                $this->logger->debug("Network interface added to OVS bridge.", InstanceLogMessage::SCOPE_PRIVATE, [
                    'NIC' => $nicName,
                    'bridge' => $bridgeName
                ]);
            }
            IPTools::linkSet($nicName, IPTools::LINK_SET_UP);
            $this->logger->debug("Network interface set up.", InstanceLogMessage::SCOPE_PRIVATE, [
                'NIC' => $nicName
            ]);

            array_push($parameters['network'],'-device','e1000,netdev='.$nicName.',mac='.$nic['macAddress'],
                '-netdev', 'tap,ifname='.$nicName.',id='.$nicName.',script=no');
        }

        if ($deviceInstance['device']['vnc'] === true) {
            $this->logger->info("VNC access requested. Adding VNC server.", InstanceLogMessage::SCOPE_PUBLIC);
            $vncAddress = "0.0.0.0";
            $vncPort = $deviceInstance['remotePort'];

            $this->logger->debug("Starting websockify process...", InstanceLogMessage::SCOPE_PUBLIC);
            
            $command = ['websockify', '-D'];
            if ($this->getParameter('app.services.proxy.wss')) {
                $this->logger->debug("Websocket use wss", InstanceLogMessage::SCOPE_PRIVATE);
                array_push($command,'--cert='.$this->getParameter('app.services.proxy.cert'),'--key='.$this->getParameter('app.services.proxy.key'));
            } else
                $this->logger->debug("Websocket without wss", InstanceLogMessage::SCOPE_PRIVATE);
            array_push($command, $vncAddress.':' . ($vncPort + 1000), $vncAddress.':'.$vncPort);
            //$command = ['websockify', '-D','--cert='.$this->getParameter('app.services.proxy.cert'),'--key='.$this->getParameter('app.services.proxy.key'),$vncAddress . ':' . ($vncPort + 1000), $vncAddress.':'.$vncPort];
            $process = new Process($command);
            $process->mustRun();
            $pidProcess = Process::fromShellCommandline("ps aux | grep " . $vncAddress . ":" . $vncPort . " | grep websockify | grep -v grep | awk '{print $2}'");
            $pidProcess->mustRun();
            $this->logger->debug("Websockify process started.", InstanceLogMessage::SCOPE_PRIVATE, [
                "PID" => (int) str_replace("\n", '', $pidProcess->getOutput())
            ]);

            array_push($parameters['access'], '-vnc', $vncAddress.':'.($vncPort - 5900));
            array_push($parameters['local'], '-k', 'fr');
        }

        array_push($parameters['local'],
            '-rtc', 'base=localtime,clock=host', // For qemu 3 compatible
            '-smp', '4',
            '-vga', 'qxl'
        );

        $arch = posix_uname()['machine'];

        $command = [
            'qemu-system-' . $arch,
            '-enable-kvm',
            '-machine', 'accel=kvm:tcg',
            '-cpu', 'max',
            '-display', 'none',
            '-daemonize',
            '-name', $uuid
        ];

        foreach ($parameters as $parametersType) {
            foreach ($parametersType as $parameter) {
                array_push($command, $parameter);
            }
        }

        $this->logger->info("Starting Virtual Machine...", InstanceLogMessage::SCOPE_PUBLIC);
        $this->logger->debug("Starting QEMU virtual machine.", InstanceLogMessage::SCOPE_PRIVATE, [
            "command" => implode(' ',$command)
        ]);

        $process = new Process($command);
        $process->mustRun();

        $this->logger->info("Virtual Machine started succesfully!", InstanceLogMessage::SCOPE_PUBLIC);
    }

    /**
     * Stop an instance described by JSON descriptor for device instance specified by UUID.
     *
     * @param string $descriptor JSON representation of a lab instance.
     * @param string $uuid UUID of the device instance to stop.
     * @throws ProcessFailedException When a process failed to run.
     * @return void
     */
    public function stopDeviceInstance(string $descriptor, string $uuid) {
        $this->logger->setUuid($uuid);
        /** @var array $labInstance */
        $labInstance = json_decode($descriptor, true, 4096, JSON_OBJECT_AS_ARRAY);

        if (!is_array($labInstance)) {
            // invalid json
            $this->logger->error("Invalid JSON was provided!", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $labInstance]);

            throw new BadDescriptorException($labInstance);
        }

        try {
            $bridgeName = $labInstance['bridgeName'];
        } catch (ErrorException $e) {
            $this->logger->error("Bridge name is missing!", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $labInstance]);
            throw new BadDescriptorException($labInstance, "", 0, $e);
        }

        // Network interfaces

        $deviceInstance = array_filter($labInstance["deviceInstances"], function ($deviceInstance) use ($uuid) {
            return ($deviceInstance['uuid'] == $uuid && $deviceInstance['state'] != 'stopped');
        });

        if (!count($deviceInstance)) {
            $this->logger->debug("Device instance is already stopped.", InstanceLogMessage::SCOPE_PUBLIC);
            // instance is already stopped or whatever
            return;
        } else {
            $deviceIndex = array_key_first($deviceInstance);
            $deviceInstance = $deviceInstance[$deviceIndex];
        }

        try {
            $labUser = $labInstance['owner']['uuid'];
            $ownedBy = $labInstance['ownedBy'];
            $labInstanceUuid = $labInstance['uuid'];
        } catch (ErrorException $e) {
            throw new BadDescriptorException($labInstance, "", 0, $e);
        }

        $process = Process::fromShellCommandline("ps aux | grep -e " . $uuid . " | grep -v grep | awk '{print $2}'");
        $process->mustRun();

        $pidInstance = $process->getOutput();

        if ($pidInstance != "") {
            $pidInstance = explode("\n", $pidInstance);

            foreach ($pidInstance as $pid) {
                if ($pid != "") {
                    $process = new Process(['kill', '-9', $pid]);
                    $process->mustRun();
                }
            }
        }

        if ($deviceInstance['device']['vnc'] === true) {
            $vncAddress = "0.0.0.0";
            $vncPort = $deviceInstance['remotePort'];

            $process = Process::fromShellCommandline("ps aux | grep " . $vncAddress . ":" . $vncPort . " | grep websockify | grep -v grep | awk '{print $2}'");
            $process->mustRun();

            $pidWebsockify = $process->getOutput();

            if (!empty($pidWebsockify)) {
                $pidWebsockify = explode("\n", $pidWebsockify);

                foreach ($pidWebsockify as $pid) {
                    if (!empty($pid)) {
                        $pid = str_replace("\n", '', $pid);
                        $process = new Process(['kill', '-9', $pid]);
                        $process->mustRun();
                        $this->logger->debug("Killing websockify process", InstanceLogMessage::SCOPE_PRIVATE, [
                            "PID" => $pid
                        ]);
                    }
                }
            }
        }
        // Network interfaces

        foreach($deviceInstance['networkInterfaceInstances'] as $networkInterfaceInstance) {
            $networkInterface = $networkInterfaceInstance['networkInterface'];
            $networkInterfaceName = substr(str_replace(' ', '_', $networkInterface['name']), 0, 6) . '-' . substr($networkInterfaceInstance['uuid'], 0, 8);

            if (OVS::ovsPortExists($bridgeName, $networkInterfaceName)) {
                OVS::portDelete($bridgeName, $networkInterfaceName, true);
            }

            if (IPTools::networkInterfaceExists($networkInterfaceName)) {
                IPTools::linkSet($networkInterfaceName, IPTools::LINK_SET_DOWN);
                $this->logger->debug("Interface ".$networkInterfaceName." set down");
                IPTools::linkDelete($networkInterfaceName);
                $this->logger->debug("Interface ".$networkInterfaceName." deleted");
            }
        }

        // OVS

        $activeDeviceCount = count(array_filter($labInstance['deviceInstances'], function ($deviceInstance) {
            return $deviceInstance['state'] == InstanceStateMessage::STATE_STARTED;
        })) - 1;

        if ($activeDeviceCount <= 0) {
            // OVS::bridgeDelete($bridgeName, true);
        }

        // $filesystem = new Filesystem();
        // $filesystem->remove($this->workerDir . '/instances/' . $labUser . '/' . $labInstanceUuid . '/' . $uuid);
    }
    
    public function connectToInternet(string $descriptor)
    {
        /** @var array $labInstance */
        $labInstance = json_decode($descriptor, true, 4096, JSON_OBJECT_AS_ARRAY);
        $labNetwork = $this->params->get('app.network.lab.cidr');
        $dataNetwork = $this->params->get('app.network.data.cidr');
        $bridgeInt = $this->params->get('app.bridge.name');
        $bridgeIntGateway = $this->params->get('app.bridge.gateway');

        IPTools::linkSet($bridgeInt, IPTools::LINK_SET_UP);

        if (!is_array($labInstance)) {
            // invalid json
            $this->logger->error("Invalid JSON was provided!", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $labInstance]);

            throw new BadDescriptorException($labInstance);
        }

        $bridge = $labInstance['bridgeName'];
        OVS::LinkTwoOVS($bridge, $bridgeInt);
        $this->logger->debug("connectToInternet - Identify bridgeName in instance:".$bridge);

        // Create new routing table for packet from the network of lab's device
        IPTools::ruleAdd('from ' . $labNetwork, 'lookup 4');
        IPTools::ruleAdd('to ' . $labNetwork, 'lookup 4');
        if (!IPTools::routeExists($dataNetwork . ' dev ' . $bridgeInt, 4)) {
            IPTools::routeAdd($dataNetwork . ' dev ' . $bridgeInt, 4);
        }
        if (!IPTools::routeExists('default via ' . $bridgeIntGateway, 4)) {
            IPTools::routeAdd('default via ' . $bridgeIntGateway, 4);
        }

        IPTables::append(
            IPTables::CHAIN_POSTROUTING,
            Rule::create()
                ->setSource($labNetwork)
                ->setOutInterface($bridgeInt)
                ->setJump('MASQUERADE')
            ,
            'nat'
        );
    }

    public function disconnectFromInternet(string $descriptor)
    {
        /** @var array $labInstance */
        $labInstance = json_decode($descriptor, true, 4096, JSON_OBJECT_AS_ARRAY);
        $labNetwork = $this->params->get('app.network.lab.cidr');
        $dataNetwork = $this->params->get('app.network.data.cidr');
        $bridgeInt = $this->params->get('app.bridge.name');
        $bridgeIntGateway = $this->params->get('app.bridge.gateway');

        if (!is_array($labInstance)) {
            // invalid json
            $this->logger->error("Invalid JSON was provided!", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $labInstance]);

            throw new BadDescriptorException($labInstance);
        }

        $bridge = $labInstance['bridgeName'];

        OVS::UnlinkTwoOVS($bridge, $bridgeInt);

        // Create new routing table for packet from the network of lab's device
        if (IPTools::ruleExists('from ' . $labNetwork, 'lookup 4')) {
            IPTools::ruleDelete('from ' . $labNetwork, 'lookup 4');
        }
        if (IPTools::routeExists($dataNetwork . ' dev ' . $bridgeInt, 4)) {
            IPTools::routeDelete($dataNetwork . ' dev ' . $bridgeInt, 4);
        }
        if (IPTools::routeExists('default via ' . $bridgeIntGateway, 4)) {
            IPTools::routeDelete('default via ' . $bridgeIntGateway, 4);
        }

        $rule = Rule::create()
            ->setSource($labNetwork)
            ->setOutInterface($bridgeInt)
            ->setJump('MASQUERADE')
        ;

        if (IPTables::exists(IPTables::CHAIN_POSTROUTING, $rule, 'nat')) {
            IPTables::delete(IPTables::CHAIN_POSTROUTING, $rule, 'nat');
        }
    }

    public function interconnect(string $descriptor)
    {
        /** @var array $labInstance */
        $labInstance = json_decode($descriptor, true, 4096, JSON_OBJECT_AS_ARRAY);
        $bridgeInt = $this->params->get('app.bridge.name');

        if (!is_array($labInstance)) {
            // invalid json
            $this->logger->error("Invalid JSON was provided!", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $labInstance]);

            throw new BadDescriptorException($labInstance);
        }
        

        //$bridge = $labInstance['instances']['bridgeName'];
        
        $bridge = $labInstance['bridgeName'];
        OVS::LinkTwoOVS($bridge, $bridgeInt);

        $this->logger->debug("connectToInternet - Identify bridgeName in instance:".$bridge);
    }

    public function disinterconnect(string $descriptor)
    {
        /** @var array $labInstance */
        $labInstance = json_decode($descriptor, true, 4096, JSON_OBJECT_AS_ARRAY);
        $bridgeInt = $this->params->get('app.bridge.name');

        if (!is_array($labInstance)) {
            // invalid json
            $this->logger->error("Invalid JSON was provided!", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $labInstance]);

            throw new BadDescriptorException($labInstance);
        }

        $bridge = $labInstance['bridgeName'];

        OVS::UnlinkTwoOVS($bridge, $bridgeInt);
    }
}
