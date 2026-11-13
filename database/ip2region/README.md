# ip2region 上游文件

来源：https://github.com/lionsoul2014/ip2region
固定版本：`200f4cc7a6452843ef515a889c72d295281e8da3`。PHP 查询客户端和 IPv4 / IPv6 数据取自同一提交，未修改上游代码。

查询实现：`library/Ip2Region/Searcher.class.php`。数据库：本目录下的两个 `.xdb` 文件。随仓库分发，无需上线时额外下载或安装 Composer 依赖。

上游许可见本目录和 `library/Ip2Region` 下的 `LICENSE.md`。

开源数据不定期更新。更换数据库时使用与该查询客户端兼容的 xdb 格式；先下载完整文件，再原子替换。数据库缓存键包含文件路径、修改时间和大小，替换后新请求会使用新缓存。

如需使用外部维护的数据，可设置 `IP2REGION_IPV4_PATH` / `IP2REGION_IPV6_PATH` 指向对应文件，并刷新配置缓存。

| 文件 | 字节数 | SHA-256 |
| --- | ---: | --- |
| `library/Ip2Region/Searcher.class.php` | 17205 | `234474ea4292904bf0b3338c87def03c5ee4e1cb467974a4bb97d5a9309bcd39` |
| `library/Ip2Region/LICENSE.md` | 12976 | `fe01f2f8fcaafac539154e6aa80b0b7f8af54e01dc4d52322f72971991c6280e` |
| `database/ip2region/ip2region_v4.xdb` | 11122742 | `a12ad5aca1277d67e525efcda531a20cc0d654ff4f087150d45bc17fca4bc260` |
| `database/ip2region/ip2region_v6.xdb` | 37283761 | `1f0be1ffbbc3e4eeef10f354e01355f22f57ec649a496a17fd602539f3d384c1` |
