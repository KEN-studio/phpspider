<?php
//----------------------------------
// PHPSpider 布隆过滤操作类文件
//----------------------------------
$redisCfg = array(
    array(
        'host' => '127.0.0.1',
        'port' => 6379,
        'db'   => 0,
        /*
    'timeout'           => 5,
    'reserved'          => null,
    'retry_interval'    => 1000,
    'read_timeout'      => 1,
     */
    ),
);
$n   = 4000000000;
$key = '1'; //redis 键名 $key:$i
$m   = $n * 50; //布隆条长度 n的50倍 16个k n为10亿条数据时，误率数为1
$k   = 16; //Hash个数

$bf = new BloomFilter($redisCfg, $key, $m, $k);
//$bf->flushall();
$word = 'abdd';
$rt   = $bf->add($word);
var_dump($rt);
if ($rt)
{
    error_log('WARNING: '.$word.' EXIST!');
}
else
{
    error_log('WARNING: '.$word.' Not EXIST!');
}

class BloomFilter
{

    public $key;
    public $m;
    public $k;
    public $nPartitions;
    public $redisCfg;
    public $nRedis;
    public $partitionSize;

    public $maxOffs = array();

    const MAX_PARTITION_SIZE = 4294967296; //redis string's max len is pow(2, 32) bits = 512MB
    //const MAX_PARTITION_SIZE = 65536;

    public function __construct($redisCfg, $key, $m, $k)
    {
        $this->nRedis = count($redisCfg);
        $mPerRedis    = $this->nRedis > 1 ? ceil($m / $this->nRedis) : $m; //$m 布隆条长度，如果有多个redis配置，则平均分布
        if ($mPerRedis > self::MAX_PARTITION_SIZE)
        {
            //由于redis最大512MB，超出需要分割为多个布隆条
            $this->nPartitions   = ceil($mPerRedis / self::MAX_PARTITION_SIZE);
            $this->partitionSize = ceil($mPerRedis / $this->nPartitions);
        }
        else
        {
            $this->nPartitions   = 1;
            $this->partitionSize = $mPerRedis;
        }
        $this->key      = $key;
        $this->m        = $mPerRedis;
        $this->k        = $k;
        $this->redisCfg = $redisCfg;
        //error_log(print_r($this, true));
    }

    private function getRedis($e)
    {
        //检查redis配置数
        if ($this->nRedis > 1)
        {
            $hash = crc32($e);
            $i    = $hash % $this->nRedis;
        }
        else
        {
            $i = 0;
        }
        $redis = SRedis::getSingeton($this->redisCfg[$i]);
        return array($i, $redis);
    }

    public function add($e)
    {
        $e                = (string) $e;
        list($ir, $redis) = $this->getRedis($e);
        //var_dump($this->key, $this->m, $this->k, $this->nRedis, $this->nPartitions, $redis, $key);
        $redis->multi(Redis::PIPELINE); //多条命令按照先后顺序被放进一个队列当中，最后由 EXEC 命令原子性(atomic)地执行
        for ($i = 0; $i < $this->k; $i++)
        {
            $seed   = self::getBKDRHashSeed($i); //随机数种子
            $hash   = self::BKDRHash($e, $seed);
            $offset = $hash % $this->m;
            $n      = floor($offset / $this->partitionSize);
            $offset = $offset % $this->partitionSize;
            $key    = $this->key.':'.$n;
            if ($offset > @$this->maxOffs[$ir.'|'.$key])
            {
                $this->maxOffs[$ir.'|'.$key] = $offset;
            }
            $redis->setbit($key, $offset, 1);
        }
        //only 4 log
        $t1   = microtime(true);
        $rt   = $redis->exec();
        $t2   = microtime(true);
        $cost = round(($t2 - $t1) * 1000, 3).'ms';
        $c    = array_sum($rt);
        error_log('['.date('Y-m-d H:i:s', time()).'] DEBUG: redis['.$ir.']-time-spent='.$cost.' maxOffset-of-'.$ir.'|'.$key.'='.$this->maxOffs[$ir.'|'.$key].' entry='.$e.' c='.$c);
        return $c === $this->k;
    }

    public function flushall()
    {
        foreach ($this->redisCfg as $cfg)
        {
            $redis = SRedis::getSingeton($cfg);
            for ($i = 0; $i < $this->nPartitions; $i++)
            {
                $redis->delete($this->key.':'.$i);
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
        $hash = 0;
        $len  = strlen($str);
        $i    = 0;
        while ($i < $len)
        {
            $hash = ((floatval($hash * $seed) & 0x7FFFFFFF) + ord($str[$i])) & 0x7FFFFFFF;
            $i++;
        }
        echo '($hash = '.$hash.PHP_EOL;
        return ($hash & 0x7FFFFFFF);
    }
}

class SRedis
{
    public static function getSingeton($cfg)
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
            $pool[$k] = $redis;
        }
        return $pool[$k];
    }
}
