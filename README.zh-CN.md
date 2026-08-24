# lumnd/plato-workerman

`plato\server\driver` 背后的 Workerman 实现：事件循环、协议与 worker 进程——[PlatoPHP](https://platophp.com)
本身刻意不提供的那三样。

[English](README.md)

`lumnd/platophp` 只负责从"一条完整消息"到"一次 `ct` / `ac` 调度"之间的事：干净的请求状态、
在连接建立时确认一次并留在连接上的身份。它不碰 socket——事件循环、进程管理和协议编解码不是框架的
职责，而协议解析器恰恰是出错就等于远程内存耗尽（而不是页面显示错误）的那一层。本包是这条缝的另一半，
`plato\server\driver` 之上的任何代码都不知道它的存在。

## 环境要求

| | |
| --- | --- |
| PHP | 8.0+ |
| Workerman | `^4.1.5 \|\| ^5.0`，两个大版本共用一套代码 |
| 扩展 | `pcntl` 与 `posix`，Workerman 的 master 进程需要 |
| 建议 | `ext-event`，单 worker 连接数上千后才有意义 |

## 安装

```bash
composer require lumnd/plato-workerman
```

框架自带的 `config/server.php` 默认就写着 `workerman`，所以装完（加上下面注册命令的那一行）即可启动：

```bash
php vendor/bin/plato server:start
```

## 配置

`config/server.php` 里框架不认识的键会原样传给本适配器。把该文件复制到应用的 `config/` 再改：

```php
return [
    'default' => 'default',

    'servers' => [
        'default' => [
            // 'workerman' 这个短名由本包注册；直接写类名同样可用，且不需要任何 bootstrap 文件
            'driver' => 'workerman',

            'listen'    => 'websocket://127.0.0.1:8282',
            'name'      => 'platophp-server',
            'processes' => 4,

            'heartbeat' => ['interval' => 30, 'timeout' => 120],

            // 由 plato\server\dispatcher 读取，不属于本适配器
            'dispatch' => ['max_payload' => 65536],
        ],
    ],
];
```

| 配置项 | 默认值 | 含义 |
| --- | --- | --- |
| `listen` | `websocket://127.0.0.1:8282` | 监听地址与协议，见下文 |
| `name` | `platophp-server` | `ps` 中的进程名，也是 pid / status / log 文件名的前缀 |
| `processes` | `4` | worker 进程数。一个进程同一时刻只处理一条消息 |
| `user` / `group` | `''` | 绑定端口后降权 |
| `pid_file` | `data_path()/server/<name>.pid` | master 的 pid 文件 |
| `status_file` | `data_path()/server/<name>.status` | `server:status` 汇总报告的位置 |
| `log_file` | `log_path()/<name>-workerman.log` | Workerman 自己的运行日志 |
| `stdout_file` | `log_path()/<name>-stdout.log` | 守护化后进程输出的去处 |
| `daemonize` | `false` | 脱离终端；`--daemon` 只对本次运行生效 |
| `graceful` | `true` | stop / restart / reload 等待 worker 处理完手上的消息 |
| `reuse_port` | `false` | `SO_REUSEPORT`，重启时不丢监听端口 |
| `protocol` | `''` | 给裸 `tcp://` 监听分包的 Workerman 协议类 |
| `max_package_size` | `0` | 从 socket 上组包的上限；`0` 保持 Workerman 的 10 MB |
| `stop_timeout` | `0` | 优雅停止后强杀前的秒数；`0` 保持 Workerman 的 2 |
| `ssl` | `[]` | `local_cert`、`local_pk`、`verify_peer`、`allow_self_signed`。只写路径，不写密钥内容 |
| `context` | `[]` | 监听 socket 的额外 stream context |
| `heartbeat` | `[]` | `interval` 与 `timeout`，单位秒。任一为 `0` 即关闭空闲清理 |
| `event_loop` | `''` | Workerman 事件循环类；协程循环会被拒绝 |
| `on_worker_start` | `null` | `fn(int $index, int $count)`，在每个 worker 知道自己是第几个之后执行 |
| `on_worker_stop` | `null` | `fn(int $index, int $count)`，退出时执行 |

TLS 就是 `ssl.local_cert` 加 `websocket://` 监听，这个组合即 `wss`。更推荐的做法仍然是在反向代理上
终结 TLS，本进程只监听 `127.0.0.1`。

## 协议

`dispatcher::handle()` 收到的必须是**一条完整的应用层消息**。用什么协议送达由适配器决定，不由框架决定：

| listen 值 | |
| --- | --- |
| `websocket://host:port` | 绝大多数客户端说的协议，也是框架默认值 |
| `text://host:port` | 一行一条消息 |
| `frame://host:port` | 4 字节大端总长度 + 载荷 |
| `tcp://host:port` + `protocol` | 任意 Workerman 协议类，包括自己写的 |

以下会被拒绝：

| 拒绝 | 原因 |
| --- | --- |
| 没有 `protocol` 的 `tcp://`、`ssl://`、`unix://` | 裸字节流没有消息边界，dispatcher 分辨不出半条消息 |
| `udp://` | 数据报没有连接，既无处保存"确认过一次"的身份，也没有回信的对象 |
| `http://` | 那是另一种请求形态；HTTP 请走 php-fpm 或框架自己的入口 |
| `ws://`、`wss://` | 这两个在 Workerman 里是**客户端**协议，服务端监听写 `websocket://` |

以上都在启动时报错说明是哪一条，而不是留下一个半能用的连接。

## 运行

在 `plato.config.php` 或 `config/config.php` 的 `console.commands` 里注册一次命令：

```php
'commands' => [plato\workerman\console::class],
```

```bash
php vendor/bin/plato server:start                    # 前台运行，直到收到信号
php vendor/bin/plato server:start --daemon           # 守护化
php vendor/bin/plato server:start --server=chat --processes=8
php vendor/bin/plato server:reload                   # 换代码，不丢监听端口
php vendor/bin/plato server:stop                     # --force 跳过优雅等待
php vendor/bin/plato server:status
php vendor/bin/plato server:connections
```

`server:start` 刻意是前台进程，交给能守着它的东西去拉起：

```ini
[Service]
ExecStart=/usr/bin/php /srv/app/vendor/bin/plato server:start
Restart=always
KillSignal=SIGTERM
TimeoutStopSec=40
```

不想用控制台的应用直接调门面即可，命令做的也就是这一件事：

```php
plato\server\server::start();
```

## 写应用

通过 socket 到达的 action 就是普通 action：用 `req` 读入参，用 `plato::$auth` 问调用者是谁，
返回一个 `plato\http\reply`：

```php
namespace control;

use plato\http\resp;
use plato\plato;
use plato\server\dispatcher;

class ctl_chat
{
    public function say()
    {
        // 身份是连接建立时确认的，挂在连接上
        $user = plato::$auth;

        // 进程内任何位置都能再次推给这个客户端
        dispatcher::current()->send(['code' => 0, 'msg' => 'delivered']);

        return resp::json(['code' => 0, 'seq' => dispatcher::seq()]);
    }
}
```

鉴权**只在 open 时做一次**。websocket 客户端唯一能用来鉴权的东西就是握手——后续帧不带任何头部——
所以本适配器把握手放在连接的 `driver::HANDSHAKE` 属性上：

```php
use plato\server\connection;
use plato\server\dispatcher;
use plato\workerman\driver;

dispatcher::on('open', function (connection $conn)
{
    $handshake = (array) $conn->get(driver::HANDSHAKE, []);
    $user      = my_auth((string) ($handshake['query']['token'] ?? ''));

    if ( $user === null )
    {
        // 驱动会关掉这个连接
        return false;
    }

    // 这个客户端之后的每条消息都以该身份调度
    $conn->set(connection::AUTH, $user);

    return true;
});
```

两个大版本的 Workerman 下，`handshake` 都是 `['path' => string, 'query' => array, 'headers' => array]`，
头部名统一小写。没有握手的协议（比如分包的 `tcp://` 监听）不会设置这个属性。

## 进程

每个 worker 在处理任何消息之前都会调用 `plato\worker::enter()`，所以分片的写法和 `plato\pool` 下完全一样：

```php
'on_worker_start' => function (int $index, int $count)
{
    // 本监听器的多个 worker 里只有一个会跑这个定时任务
    if ( plato\worker::owns() )
    {
        Workerman\Timer::add(60, 'my_sweep');
    }
},
```

框架明说、本适配器照做的两条：

- **一个进程同一时刻只处理一条消息。** 请求状态放在静态属性里，协程调度器在同一个 pid 内跑两次调度就会
  互相污染。Workerman 5 的 `Fiber`、`Swoole`、`Swow` 循环会被直接拒绝，而不是"部分支持"。
- **`send()` 只到本进程。** worker 之间不共享内存，`driver::connections()` 和 `server::send()` 都不假装
  自己能跨进程。要广播到所有 worker，必须借助双方都能看到的外部通道（Redis pub/sub 之类）。

## 测试

```bash
composer test          # Unit + Feature
composer analyse       # phpstan level 5，无基线
composer style         # phpcs，0 error
```

Feature 用例会在子进程里起真实监听器，并用手写的 websocket 握手和手写的分帧通过真实 socket 通信——
和服务端共用一套分帧代码的客户端，证明不了协议层的任何事。

## 许可证

MIT。安全问题请发到 [SECURITY.md](SECURITY.md) 里的地址。
