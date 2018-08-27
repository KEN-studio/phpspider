<?php
//----------------------------------
// PHPSpider 布隆过滤操作类文件 默认使用 db:0
// ADD BY KEN a-site@foxmail.com
// 内存占用预估：600MB/亿条数据
//----------------------------------
namespace phpspider\core;

use Exception;
use phpspider\core\log;
use Redis;

class BloomFilter
{
    /**
     *  redis链接标识符号
     */
    public static $redis = null;
    //redis配置，默认共用queue配置
    public static $config = array(
        //'host'    => '/var/run/redis/redis.sock', //127.0.0.1
        'host'    => '127.0.0.1', //127.0.0.1
        'port'    => '6379', //6379
        'pass'    => '',
        'db'      => 0, //占用redis数据库id
        'prefix'  => '', //根据任务设置不同的去重库，一般无需设置
        'timeout' => 3,
        //布隆过滤器专用配置项
        'n'       => 500000000, //总数据量上限 默认5亿，建议不超过40亿
        'k'       => 16,
    );
    protected static $configs = array();
    private static $links     = array();
    private static $link_name = 'default';

    //过滤器参数
    public static $fix_key       = 'bl'; //任务名：redis 键名 $key:$i
    public static $m             = 500000000 * 50; //布隆条总长度，默认5亿数据*50倍 占用3GB内存 600MB/亿数据
    public static $k             = 16; //Hash个数
    public static $nPartitions   = 0;
    public static $partitionSize = 0;

    public static $maxOffs = array(); //当前最大偏移量 偏移量之前的内存空间会用0填充占用

    //redis string's max len is pow(2, 32) 4294967296 bits = 512MB PHP 32位 INT 最大值 pow(2, 31) 2147483648 bits = 256MB
    const MAX_PARTITION_SIZE = 4294967296;
    //const MAX_PARTITION_SIZE = 65536;

    //初始化时需传入redis配置
    public static function init()
    {
        if ( ! extension_loaded('redis'))
        {
            self::$error = 'The redis extension was not found';
            return false;
        }

        // 获取配置
        $config = self::$link_name == 'default' ? self::$configs['default'] : self::$configs[self::$link_name];

        // 如果当前链接标识符为空，或者ping不同，就close之后重新打开
        //if ( empty(self::$links[self::$link_name]) || !self::ping() )
        if (empty(self::$links[self::$link_name]))
        {
            self::$links[self::$link_name] = new Redis();
            if (strstr($config['host'], '.sock'))
            {
                if ( ! self::$links[self::$link_name]->connect($config['host']))
                {
                    self::$error = 'Unable to connect to redis server';
                    unset(self::$links[self::$link_name]);
                    return false;
                }
            }
            else
            {
                if ( ! self::$links[self::$link_name]->connect($config['host'], $config['port'], $config['timeout']))
                {
                    self::$error = 'Unable to connect to redis server';
                    unset(self::$links[self::$link_name]);
                    return false;
                }
            }

            // 验证
            if ($config['pass'])
            {
                if ( ! self::$links[self::$link_name]->auth($config['pass']))
                {
                    self::$error = 'Redis Server authentication failed';
                    unset(self::$links[self::$link_name]);
                    return false;
                }
            }

            //强制共用同1个重库时，不用前缀
            //$prefix = empty($config['prefix']) ? self::$prefix : $config['prefix'];
            //self::$links[self::$link_name]->setOption(Redis::OPT_PREFIX, $prefix.':');
            // 永不超时
            // ini_set('default_socket_timeout', -1); 无效，要用下面的做法
            self::$links[self::$link_name]->setOption(Redis::OPT_READ_TIMEOUT, -1);
            //强制共用0号db
            self::$links[self::$link_name]->select(0);
        }

        if (empty(self::$nPartitions) and empty(self::$partitionSize))
        {
            //重设置布隆条长度
            if ( ! empty($config['n']))
            {
                self::$m = $config['n'] * 50; //50倍数据量长度
            }
            //重设键长度
            if ( ! empty($config['k']))
            {
                self::$k = $config['k']; //默认为16
            }
            //初始化布隆条长度和个数
            if (self::$m > self::MAX_PARTITION_SIZE)
            {
                //由于redis最大512MB，超出需要拆分为多个布隆条
                self::$nPartitions   = ceil(self::$m / self::MAX_PARTITION_SIZE); //布隆条拆分个数
                self::$partitionSize = ceil(self::$m / self::$nPartitions); //每个布隆条长度 或 512M self::MAX_PARTITION_SIZE
            }
            else
            {
                self::$nPartitions   = 1;
                self::$partitionSize = self::$m;
            }
        }
        //error_log(print_r($this, true));
        return self::$links[self::$link_name];
    }

    public static function set_connect($link_name, $config = array())
    {
        self::$link_name = $link_name;
        if ( ! empty($config))
        {
            self::$configs[self::$link_name] = $config;
        }
        else
        {
            if (empty(self::$configs[self::$link_name]))
            {
                throw new Exception('You not set a config array for connect!');
            }
        }
        //print_r(self::$configs);

        //// 先断开原来的连接
        //if ( !empty(self::$links[self::$link_name]) )
        //{
        //self::$links[self::$link_name]->close();
        //self::$links[self::$link_name] = null;
        //}
    }

    public static function add($e)
    {
        self::init();
        $e = (string) $e;
        if (empty(self::$links[self::$link_name]))
        {
            throw new Exception('You not set a redis connect!');
        }

        //self::$links[self::$link_name]->multi(Redis::PIPELINE); //多条命令按照先后顺序被放进一个队列当中，最后由 EXEC 命令原子性(atomic)地顺序执行
        self::$links[self::$link_name]->pipeline(); //多条命令按照先后顺序被放进一个队列当中，并同时执行，不需要原子性地顺序执行
        $irs          = array();
        $mt_rand_seed = sprintf('%u', crc32($e));
        if ($mt_rand_seed > PHP_INT_MAX)
        {
            $mt_rand_seed = $mt_rand_seed % PHP_INT_MAX;
        }
        //生成随机位置
        mt_srand($mt_rand_seed);
        for ($i = 0; $i < self::$k; $i++)
        {
            //生成布隆条位置
            if ($i === 0)
            {
                $hash = sprintf('%u', crc32(md5($e))) % self::$m;
            }
            elseif ($i === 1)
            {
                $hash = sprintf('%u', crc32(sha1($e))) % self::$m;
            }
            else
            {
                //$seed       = self::getBKDRHashSeed($i); //随机数种子
                //$hash       = self::BKDRHash($e, $seed); //Hash数字
                $hash = mt_rand(1, self::$m - 1); //使用 crc32为随机数种子的随机数
            }

            //$hash = $hash % self::$m; //总偏移量
            $ir = 0;
            if ($hash > self::$partitionSize)
            {
                $ir     = floor($hash / self::$partitionSize); //布隆条ID
                $offset = $hash % self::$partitionSize; //布隆条内偏移量
            }
            else
            {
                $offset = $hash;
            }
            $key = self::$fix_key.':'.$ir; //存储布隆条的ID
            //echo '$hash = '.$hash.' $offset = '.$offset.' $hash = '.$hash.' $key ='.$key.PHP_EOL;
            //$irs[] = $offset;
            //记录当前各key最大偏移量，统计内存占用情况
            //if ($offset > @self::$maxOffs[$ir.'|'.$key])
            //{
            //    self::$maxOffs[$ir.'|'.$key] = $offset;
            //}
            self::$links[self::$link_name]->setbit($key, $offset, 1); //将指定偏移位置设置为1
        }
        //only for log
        //$t1   = microtime(true);
        $rt   = self::$links[self::$link_name]->exec();
        //$t2   = microtime(true);
        //$cost = round(($t2 - $t1) * 1000, 3).'ms';
        //偏移位置已经为1的个数
        $c = array_sum($rt);

        //only for log
        $exists = 'not exists';
        if ($c === self::$k)
        {
            $exists = 'exists';
        }
        //$msg = ('['.date('Y-m-d H:i:s', time()).'] DEBUG: redis['.$ir.']-time-spent='.$cost.' maxOffset-of-'.$ir.'|'.$key.'='.self::$maxOffs[$ir.'|'.$key].' entry='.$e.' '.$exists.' c='.$c).' nPartitions='.self::$nPartitions.' partitionSize='.self::$partitionSize.' Max_mem='.round((self::$nPartitions * (self::$partitionSize) / 8 / 1024 / 1024), 2).'M, Current_mem= '.round(array_sum(self::$maxOffs) / 8 / 1024 / 1024, 2).'M'.PHP_EOL;
        //sort($irs);
        $msg = 'entry='.$e.' '.$exists.' c='.$c.'/'.self::$k.PHP_EOL;
        //echo $msg;
        log::error($msg);

        //如果所有偏移位置均为1，则表示此内容为重复
        return $c === self::$k;
    }

    //清除去重库
    public static function delete_bloom_box()
    {
        self::init();
        for ($i = 0; $i < self::$nPartitions; $i++)
        {
            self::$links[self::$link_name]->delete(self::$fix_key.':'.$i);
        }
    }
}
