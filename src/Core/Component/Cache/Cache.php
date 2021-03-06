<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2018/1/18
 * Time: 下午12:17
 */

namespace EasySwoole\Core\Component\Cache;


use EasySwoole\Config;
use EasySwoole\Core\AbstractInterface\Singleton;
use EasySwoole\Core\Component\Di;
use EasySwoole\Core\Component\SysConst;
use EasySwoole\Core\Swoole\Process\ProcessManager;
use EasySwoole\Core\Swoole\ServerManager;
use EasySwoole\Core\Utility\Random;

class Cache
{
    use Singleton;
    private $processNum;
    private $processList = [];
    function __construct()
    {
        $num = intval(Config::getInstance()->getConf("EASY_CACHE.PROCESS_NUM"));
        if($num <= 0){
           return;
        }
        $this->processNum = $num;
        for ($i=0;$i < $num;$i++){
            $processName = "process_cache_{$i}";
            $hash = ProcessManager::getInstance()->addProcess(CacheProcess::class,false,$processName);
            $this->processList[$processName] = ProcessManager::getInstance()->getProcessByHash($hash);
        }
    }

    private function keyToProcessNum($key):int
    {
        return base_convert( md5( $key,true ), 16, 10 )%$this->processNum;
    }

    /*
     * 默认等待0.01秒的调度
     */
    public function get($key,$timeOut = 0.01)
    {
        $num = $this->keyToProcessNum($key);
        if(ServerManager::getInstance()->isStart()){
            $token = Random::randStr(9);
            $process = $this->processList["process_cache_{$num}"];
            $process->getProcess()->write(\swoole_serialize::pack([
                'command'=>'get',
                'args'=>[
                    'key'=>$key,
                    'token'=>$token
                ],
                'timeOut'=>$timeOut
            ]));
            while (1){
                $info = ProcessManager::getInstance()->readByHash($process->getHash(),$timeOut);
                if(!empty($info)){
                    $data = \swoole_serialize::unpack($info);
                    if(isset($data['token']) && $data['token'] == $token){
                        return $data['data'];
                    }else{
                        //参与重新调度  兼容携程
                        $data['command'] = 'reDispatch';
                        $process->getProcess()->write(\swoole_serialize::pack($data));
                    }
                }else{
                    return null;
                }
            }
        }else{
            //为单元测试服务
            $file = Di::getInstance()->get(SysConst::DIR_TEMP)."/process_cache_{$num}.data";
            if(file_exists($file)){
                $content = file_get_contents($file);
                $data = \swoole_serialize::unpack($content);
                if(isset($data[$key])){
                    return $data[$key];
                }else{
                    return null;
                }
            }else{
                return null;
            }
        }
    }

    public function set($key,$data)
    {
        $num = $this->keyToProcessNum($key);
        if(ServerManager::getInstance()->isStart()){
            $this->processList["process_cache_{$num}"]->getProcess()->write(\swoole_serialize::pack([
                'command'=>'set',
                'args'=>[
                    'key'=>$key,
                    'data'=>$data
                ]
            ]));
        }else{
            //为单元测试服务
            $file = Di::getInstance()->get(SysConst::DIR_TEMP)."/process_cache_{$num}.data";
            if(file_exists($file)){
                $content = file_get_contents($file);
                $data = \swoole_serialize::unpack($content);
            }else{
                $data[$key] = $data;
            }
            file_put_contents($file,\swoole_serialize::pack($data));
        }
    }

    function del($key)
    {
        $num = $this->keyToProcessNum($key);
        if(ServerManager::getInstance()->isStart()){
            $this->processList["process_cache_{$num}"]->write(\swoole_serialize::pack([
                'command'=>'del',
                'args'=>[
                    'key'=>$key,
                ]
            ]));
        }else{
            //为单元测试服务
            $file = Di::getInstance()->get(SysConst::DIR_TEMP)."/process_cache_{$num}.data";
            if(file_exists($file)){
                $content = file_get_contents($file);
                $data = \swoole_serialize::unpack($content);
                if(isset($data[$key])){
                    unset($data[$key]);
                }
            }else{
                $data = [];
            }
            file_put_contents($file,\swoole_serialize::pack($data));
        }
    }

    function flush()
    {
        for ($i=0;$i<$this->processNum;$i++){
            if(ServerManager::getInstance()->isStart()){
                $this->processList["process_cache_{i}"]->write(\swoole_serialize::pack([
                    'command'=>'flush',
                    'args'=>[]
                ]));
            }else{
                $file = Di::getInstance()->get(SysConst::DIR_TEMP)."/process_cache_{$i}.data";
                file_put_contents($file,\swoole_serialize::pack([]));
            }
        }
    }
}