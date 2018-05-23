# phpspider -- PHP蜘蛛爬虫框架
《我用爬虫一天时间“偷了”知乎一百万用户，只为证明PHP是世界上最好的语言 》所使用的程序
原版地址：https://github.com/owner888/phpspider

phpspider是一个爬虫开发框架。使用本框架，你不用了解爬虫的底层技术实现，爬虫被网站屏蔽、有些网站需要登录或验证码识别才能爬取等问题。简单几行PHP代码，就可以创建自己的爬虫，利用框架封装的多进程Worker类库，代码更简洁，执行效率更高速度更快。

demo目录下有一些特定网站的爬取规则，只要你安装了PHP环境，代码就可以在命令行下直接跑。 对爬虫感兴趣的开发者可以加QQ群一起讨论：147824717。

下面以糗事百科为例, 来看一下我们的爬虫长什么样子:

```
$configs = array(
    'name' => '糗事百科',
    'tasknum' => 5,//并发进程数，限在linux中有效，windows中只有单进程
    'domains' => array(
	//新增参数 1
	//'*',//通配所有域名，不限定域名抓取，使用此项时，请同时指定抓取深度最好不要超过3
        'qiushibaike.com',
        'www.qiushibaike.com'
    ),
    'max_depth' => 2, //网页深度，超过深度的页面不再采集 默认值为0，即不限制
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
    //新增参数 针对全网泛爬
	'sub_num' => 1000, //限制最大子域名数量
	'max_pages' => 10000, //限制单域名最大抓取页面数量
	'max_duration' => 10000 * 7, //限制单域名慢速抓取最大时长 10000页 x 300秒
	'queue_order' => 'rand', //泛抓取时请开始此项，队列抓取顺序 随机抽取：rand，正常先进先出：normal 或留空

);
$spider = new phpspider($configs);
$spider->start();
```
爬虫的整体框架就是这样, 首先定义了一个$configs数组, 里面设置了待爬网站的一些信息, 然后通过调用```$spider = new phpspider($configs);```和```$spider->start();```来配置并启动爬虫.

#### 运行界面如下:

![](http://www.epooll.com/zhihu/pachong.gif)

更多详细内容，移步到：

[开发文档](http://doc.phpspider.org)
