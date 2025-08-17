<?php
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use Cache\Adapter\Filesystem\FilesystemCachePool;

require __DIR__ . '/vendor/autoload.php';

class Synology {
    private $cache;
    private $url;
    private $sid;
    private $apiInfo;
    private $user;
    private $password;

    private function getData($url, $expectedCode = 200, $expectedMimeType = "application/json") {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_URL, $url);
        $result = curl_exec($ch);

        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if($code != $expectedCode) {
            throw new \Exception("Transfer did not return the expected code $expectedCode. It returned $code");
        }

        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $mimeType = trim(explode(";",$contentType, 2)[0]);
        if($mimeType != $expectedMimeType) {
            throw new \Exception("Transfer did not return the expected content-type $expectedMimeType. It returned $mimeType");
        }
        curl_close($ch);

        return $result;
    }
    private function getAPIInfo(array $apiNames = ['SYNO.API.Auth', 'SYNO.SurveillanceStation.Camera']) {
        $apiInfoCache = $this->cache->getItem('APIInfo');
        if($apiInfoCache->isHit()) {
            return $apiInfoCache->get();
        }

        $json = file_get_contents($this->url.'/webapi/query.cgi?api=SYNO.API.Info&method=Query&version=1&query='.implode(",",$apiNames));
        $obj = json_decode($json);
        if($obj->success != true) {
            throw new \Exception("Failed to get API Info.");
        }
        $apiInfoCache->set($obj->data);
        $this->cache->save($apiInfoCache);
        return $obj->data;
    }

    public function __construct($url, $cache, $user, $password) {
        $this->url = $url;
        $this->cache = $cache;
        $this->user = $user;
        $this->password = $password;

        $this->apiInfo = $this->getAPIInfo();
        $this->authenticate();
    }
    
    private function reauthenticate() {
        $this->cache->deleteItem('SID');
        $this->authenticate();
    }

    private function authenticate() {
        $sidCache = $this->cache->getItem('SID');
        if($sidCache->isHit()) {
            $this->sid = $sidCache->get();
            return;
        }
        //Get SYNO.API.Auth Path (recommended by Synology for further update)
        $json = file_get_contents($this->url.'/webapi/query.cgi?api=SYNO.API.Info&method=Query&version=1&query=SYNO.API.Auth');
        $obj = json_decode($json);
        $AuthPath = $this->apiInfo->{'SYNO.API.Auth'}->path;
        // Authenticate with Synology Surveillance Station WebAPI and get our SID 
        $json = file_get_contents($this->url.'/webapi/'.$AuthPath.'?api=SYNO.API.Auth&method=Login&version=6&account='.$this->user.'&passwd='.$this->password.'&session=SurveillanceStation&format=sid'); 
        $obj = json_decode($json); 
        //Check if auth ok
        if($obj->success != "true"){
            throw new \Exception("Failed to Authenticate.");
        }
        $this->sid = $obj->data->sid;
        $sidCache->set($this->sid);
        $this->cache->save($sidCache);
    }

    public function getSnapshot($cameraId, $cameraStream) {
        $snapCache = $this->cache->getItem('Snap_'.$cameraId."_".$cameraStream);
        if($snapCache->isHit()) {
            return $snapCache->get();
        }
        $CamPath = $this->apiInfo->{'SYNO.SurveillanceStation.Camera'}->path;
        $url = $this->url.'/webapi/'.$CamPath.'?profileType='.$cameraStream.'&version=9&id='.$cameraId.'&api=SYNO.SurveillanceStation.Camera&method=GetSnapshot&_sid=';
        try {
            $image = $this->getData($url.$this->sid, 200, "image/jpeg");
        }
        catch (\Exception $e) {
            $this->reauthenticate();
            $image = $this->getData($url.$this->sid, 200, "image/jpeg");
        }
        $snapCache->set($image);
        $snapCache->expiresAfter(10);
        $this->cache->save($snapCache);
        return $image;
    }
}

ini_set("display_errors", 0);

$filesystemAdapter = new Local(__DIR__.'/cache/');
$filesystem        = new Filesystem($filesystemAdapter);
$cache = new FilesystemCachePool($filesystem);

$syno = new \Synology("https://example.com:5001", $cache, "FILL_ME", "FILL_ME");

$camera = intval($_GET['camera']);
$profile = intval($_GET['profile']);

if(!in_array($camera, [3,4,5]) || !in_array($profile, [0,1,2])) {
    throw new \Exception("Unsupported parameters.");
}

# 0 - high quality
# 2 - low quality
$image = $syno->getSnapshot($camera, $profile);

header("Content-Type: image/jpeg");
header("Content-Length: ".strlen($image));
echo $image;
