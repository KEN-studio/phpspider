<?php
//----------------------------------
// PHPSpider 布隆过滤操作类文件 默认使用 db:0
// ADD BY KEN a-site@foxmail.com
//----------------------------------
namespace phpspider\core;
use Redis;

class BloomFilter
{
    /**
     *  redis链接标识符号
     */
    protected static $redis = null;

    /**
     *  redis配置数组
     */
    protected static $redisCfg = array(array('host' => '127.0.0.1', 'port' => 6379, 'db' => 0));
    private static $links      = array();
    private static $link_name  = 'default';
    public static $nRedis;
    //过滤器参数
    public static $fix_key       = 'bl'; //任务名：redis 键名 $key:$i
    public static $m             = 4000000000 * 50; //布隆条总长度，默认40亿数据*50倍
    public static $k             = 16; //Hash个数
    public static $nPartitions   = 0;
    public static $partitionSize = 0;

    public static $maxOffs = array(); //当前最大偏移量 偏移量之前的内存空间会用0填充占用

    //redis string's max len is pow(2, 32) 4294967296 bits = 512MB PHP 32位 INT 最大值 pow(2, 31) 2147483648 bits = 256MB
    const MAX_PARTITION_SIZE = 4294967296;
    //const MAX_PARTITION_SIZE = 65536;

    public static function init()
    {
        self::$nRedis = count(self::$redisCfg);
        $mPerRedis    = self::$nRedis > 1 ? ceil(self::$m / self::$nRedis) : self::$m; //$m 布隆条总长度，如果有多个redis配置，则平均分布
        if ($mPerRedis > self::MAX_PARTITION_SIZE)
        {
            //由于redis最大512MB，超出需要拆分为多个布隆条
            self::$nPartitions   = ceil($mPerRedis / self::MAX_PARTITION_SIZE); //布隆条拆分个数
            self::$partitionSize = ceil($mPerRedis / self::$nPartitions); //每个布隆条长度 或 512M self::MAX_PARTITION_SIZE
        }
        else
        {
            self::$nPartitions   = 1;
            self::$partitionSize = $mPerRedis;
        }
        //error_log(print_r($this, true));
    }

    public static function add($e)
    {
        self::init();
        $e                = (string) $e;
        list($ir, $redis) = self::getRedis($e);
        //var_dump(self::$fix_key, self::$m, self::$k, self::$nRedis, self::$nPartitions, $redis, $key);
        $redis->multi(Redis::PIPELINE); //多条命令按照先后顺序被放进一个队列当中，最后由 EXEC 命令原子性(atomic)地执行
        for ($i = 0; $i < self::$k; $i++)
        {
            $seed   = self::getBKDRHashSeed($i); //随机数种子
            $hash   = self::BKDRHash($e, $seed); //Hash数字
            $offset = $hash % self::$m; //总偏移量
            $n      = 0;
            if ($offset > self::$partitionSize)
            {
                $n      = floor($offset / self::$partitionSize); //布隆条ID
                $offset = $offset % self::$partitionSize; //布隆条内偏移量
            }
            $key = self::$fix_key.':'.$n; //存储布隆条的redis key
            //记录当前各key最大偏移量，统计内存占用情况
            if ($offset > @self::$maxOffs[$ir.'|'.$key])
            {
                self::$maxOffs[$ir.'|'.$key] = $offset;
            }
            $redis->setbit($key, $offset, 1); //将指定偏移位置设置为1
        }
        //only for log
        //$t1   = microtime(true);
        $rt   = $redis->exec();
        //$t2   = microtime(true);
        //$cost = round(($t2 - $t1) * 1000, 3).'ms';
        //偏移位置已经为1的个数
        $c = array_sum($rt);

        //only for log
        //$exists = 'not exists';
        //if ($c === self::$k) {
        //    $exists = 'exists';
        //}
        //$msg = ('['.date('Y-m-d H:i:s', time()).'] DEBUG: redis['.$ir.']-time-spent='.$cost.' maxOffset-of-'.$ir.'|'.$key.'='.self::$maxOffs[$ir.'|'.$key].' entry='.$e.' '.$exists.' c='.$c).' nPartitions='.self::$nPartitions.' partitionSize='.self::$partitionSize.' Max_mem='.round((self::$nPartitions * (self::$partitionSize) / 8 / 1024 / 1024), 2).'M, Current_mem= '.round(array_sum(self::$maxOffs) / 8 / 1024 / 1024, 2).'M'.PHP_EOL;
        //log::debug($msg);

        //如果所有偏移位置均为1，则表示此内容为重复
        return $c === self::$k;
    }

    public static function flushall()
    {
        foreach (self::$redisCfg as $cfg)
        {
            $redis = self::getSingeton($cfg);
            for ($i = 0; $i < self::$nPartitions; $i++)
            {
                $redis->delete(self::$fix_key.':'.$i);
            }
        }
    }

    public static function getBKDRHashSeed($n)
    {
        if ($n === 0)
        {
            return 31;
        }

        $j = $n + 2;
        $r = 0;
        for ($i = 0; $i < $j; $i++)
        {
            if ($i % 2)
            {
                $r = $r * 10 + 3; // 奇数
            }
            else
            {
                $r = $r * 10 + 1;
            }
        }
        return $r;
    }

    public static function BKDRHash($str, $seed)
    {
        $hash = 1;
        $len  = strlen($str);
        $i    = 0;
        while ($i < $len)
        {
            $hash = ((floatval($hash * $seed) & PHP_INT_MAX) + ord($str[$i])) & PHP_INT_MAX;
            $i++;
        }
        return ($hash & PHP_INT_MAX); //0x7FFFFFFF PHP_INT_MAX 或 PHP_INT_MAX 64位可使用此值
    }

    //获取redis服务器：有多个库则平均分布
    private static function getRedis($e)
    {
        //检查redis配置数，多个则为分布式
        if (self::$nRedis > 1)
        {
            $hash = sprintf('%u', crc32($e));
            $i    = $hash % self::$nRedis;
        }
        else
        {
            $i = 0;
        }
        $redis = self::getSingeton(self::$redisCfg[$i]);
        return array($i, $redis);
    }

    //初始化redis连接
    private static function getSingeton($cfg)
    {
        static $pool;
        if (empty($cfg) || ! is_array($cfg))
        {
            return false;
        }
        $k = serialize($cfg);
        if (empty($pool[$k]))
        {
            $redis = new Redis();
            call_user_func_array(array($redis, 'connect'), array_values($cfg));
            // 永不超时
            // ini_set('default_socket_timeout', -1); 无效，要用下面的做法
            //$redis->setOption(Redis::OPT_READ_TIMEOUT, -1);
            $pool[$k] = $redis;
        }
        return $pool[$k];
    }
}
