## phpspider -- PHP蜘蛛爬虫框架 [KEN分支修改版]

《我用爬虫一天时间“偷了”知乎一百万用户，只为证明PHP是世界上最好的语言 》所使用的程序
原版地址：https://github.com/owner888/phpspider

v2.2.0 更新内容 (20180826)
1. 网址去重改为布隆去重算法
2. 升级后与原版任务不兼容，故版本号更改为 2.2.0 （用此版程序运行旧任务，原网址已经去重的部分将会重新爬取）
3. 调整部分默认参数为关闭状态
4. 合并原版：RESTful的bug修复，和$save_running_status的bug修复

20180718 合并2.1.5的更新
1. selector默认返回null，而不是false，因为isset(false)为true，解决了字段设置 required => true依然获取字段的bug
2. 添加了on_before_download_page回调，比如有时候需要根据某个特定URL，来决定是否使用代理或使用那个代理
3. 修复db类处理事务的bug
4. 采集一个URL时先删除上一个URL的代理和伪造IP，以免被自动带上代理
5. 添加请求页面语言
6. requests类默认把采集到的内容转utf-8，因为xpath需要utf-8支持
7. 修复a标签相对路径错误的bug（这是本分支的fill_url Plan A，本分支采取的Plan B方案，等对比测试一下再决定用哪套方案）

20180530 更新
~~~
    //增加要排除的内容页特征正则
    'content_url_regexes_remove' => array(
        '.360webcache.com',
        '.so.com',
        '.360.com',
        '.baidu.com',
        'cache.baiducontent.com',
        '.sogou.com',
        'snapshot.sogoucdn.com',
        '.bing.com',
        '.bingj.com',
    ),
~~~

20180529变动
- 按host设置并发上限
- 优化部分功能性能
- 修复少量bug

针对全网泛域名爬行优化：
- 增加多个参数
- 可限制单域名最大采集页数
- 可限制最大子域名数
- 记录慢反应域名总花费时间，防止低速域名浪费资源
- 增加随机提取任务模式，用于多域名并发，充分利用带宽

常驻内存循环采集优化：
- 配置存入缓存，子进程可实时读取

-----------------------------
## phpspider -- PHP蜘蛛爬虫框架 [介绍]

phpspider是一个爬虫开发框架。使用本框架，你不用了解爬虫的底层技术实现，爬虫被网站屏蔽.有些网站需要登录或验证码识别才能爬取等问题。简单几行PHP代码，就可以创建自己的爬虫，利用框架封装的多进程Worker类库，代码更简洁，执行效率更高速度更快。

demo目录下有一些特定网站的爬取规则，只要你安装了PHP环境，代码就可以在命令行下直接跑。 对爬虫感兴趣的开发者可以加QQ群一起讨论：147824717。

下面以糗事百科为例, 来看一下我们的爬虫长什么样子:

```
$configs = array(
    'name' => '糗事百科',
    'tasknum' => 5,//并发进程数，限在linux中有效，windows中只有单进程
    'domains' => array(
	//新增参数 1
	//'*',//通配所有域名，不限定域名抓取，使用此项时，请同时指定抓取深度 max_depth 最好不要超过3
        'qiushibaike.com',
        'www.qiushibaike.com'
    ),
    'max_depth' => 2, //网页抓取深度，超过深度的页面不再采集 默认值为0，即不限制
    'scan_urls' => array(
        'http://www.qiushibaike.com/'
    ),
    'content_url_regexes' => array(
        "http://www.qiushibaike.com/article/\d+"
	//新增参数 1
	//"*",//可设置为 * ，即所有页面均为内容页，都需要提取fields
	//新增参数 2
	"x", //无内容页，即所有页面均不提取fields，配合 所有页面均为list页
    ),
    'list_url_regexes' => array(
        "http://www.qiushibaike.com/8hr/page/\d+\?s=\d+"
	//新增参数 1
	"x", //无list页，配合 所有页面均为内容页
	//新增参数 2
	"*", //所有页面均为list页
    ),
    'fields' => array(
        array(
            // 抽取内容页的文章内容
            'name' => "article_content",
            'selector' => "//*[@id='single-next-link']",
            'required' => true
        ),
        array(
            // 抽取内容页的文章作者
            'name' => "article_author",
            'selector' => "//div[contains(@class,'author')]//h2",
            'required' => true
        ),
    ),
    //新增参数
	'max_sub_num'	=> 3000, //限制最大子域名数量
	'max_pages'	=> 100000, //限制单域名最大抓取页面数量
	'max_duration'	=> 10000 * 7, //限制单域名慢速抓取最大耗时 10000页 x 7秒
	'queue_order'	=> 'rand', //泛抓取时请开始此项，队列抓取顺序 随机抽取：rand，正常先进先出：normal/list 或留空
	'max_task_per_host'	=> 6, //每个目标主机并发上限

);
$spider = new phpspider($configs);
$spider->start();
```
爬虫的整体框架就是这样, 首先定义了一个$configs数组, 里面设置了待爬网站的一些信息, 然后通过调用```$spider = new phpspider($configs);```和```$spider->start();```来配置并启动爬虫.

#### 运行界面如下:

![](http://www.epooll.com/zhihu/pachong.gif)

更多详细内容，移步到：

[开发文档](http://doc.phpspider.org)
