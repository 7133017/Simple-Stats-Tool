# Simple Web Stats (PV/UV Edition)
轻量级 PHP 网站流量统计工具，专注于 PV（页面浏览量）和 UV（独立访客数）统计，无需复杂依赖，基于 SQLite 数据库，开箱即用。

## 🌟 核心特性
- 📊 精准统计：区分 PV（浏览量）和 UV（独立访客数），支持今日/昨日/总计数据展示
- 🔒 安全防护：管理员密码验证，数据仅管理员可查看
- 🕷️ 机器人过滤：自动识别爬虫/机器人请求，排除无效数据
- 🌐 跨域支持：统计接口允许跨域请求，适配多域名部署
- 📱 响应式设计：适配手机/平板/桌面等多终端访问
- ⚡ 性能优化：SQLite WAL 模式 + 索引优化，低资源消耗
- 🛠️ 简易集成：一行 JS 代码即可接入任意网站

## 📋 环境要求
- PHP 7.0+ (推荐 7.4+)
- 开启 PDO_SQLite 扩展（大部分主机默认开启）
- 网站目录可写权限（用于创建 SQLite 数据库文件）

## 🚀 快速部署
1. 将代码保存为 `stats.php` 上传到你的网站目录（如根目录）
2. 访问 `https://你的域名/stats.php`
3. 首次访问会提示设置管理员密码，完成初始化
4. 复制后台生成的集成代码，添加到需要统计的网站页面底部 `</body>` 之前

<img width="1092" height="1390" alt="QQ截图20251216144057" src="https://github.com/user-attachments/assets/bc620f50-e1f8-4266-adc6-022a0d7147ee" />

## 📖 使用说明
### 1. 初始化配置
- 首次访问工具会要求设置管理员密码
- 密码采用 PHP `password_hash` 加密存储，安全可靠
- 忘记密码可删除 `stats.db` 文件重新初始化（会清空所有统计数据）

### 2. 集成统计代码
后台面板会自动生成适配你的域名的集成代码，示例：
```javascript
<script>
(function() {
    var vid = localStorage.getItem('stats_vid');
    if (!vid) {
        vid = Math.random().toString(36).substring(2) + Date.now().toString(36);
        localStorage.setItem('stats_vid', vid);
    }
    var img = new Image();
    var p = encodeURIComponent(window.location.pathname);
    var r = encodeURIComponent(document.referrer);
    img.src = 'https://你的域名/stats.php?action=record&path=' + p + '&referer=' + r + '&vid=' + vid;
})();
</script>
```
将此代码添加到所有需要统计的页面底部即可自动收集数据。

### 3. 查看统计数据
- 登录后台可查看：
  - 今日/昨日/总计的 PV/UV 数据
  - 热门页面排行（按 PV/UV 排序）
  - 访问来源域名统计
  - 完整的访问日志

### 4. 管理功能
- 修改管理员密码：登录后点击"设置"按钮
- 安全退出：点击"退出"按钮，销毁登录会话

## 📊 数据说明
| 指标 | 说明 |
|------|------|
| PV (Page View) | 页面浏览量，用户每访问一次页面计为 1 PV |
| UV (Unique Visitor) | 独立访客数，基于本地存储的唯一 ID 统计，同一用户当日多次访问仅计为 1 UV |
| 页面路径 | 访问的页面 URL 路径，自动拼接完整域名便于访问 |
| 来源 URL | 访客的跳转来源，可分析流量渠道 |

## 🔧 技术细节
- **数据库**：SQLite 嵌入式数据库，无需额外配置
- **UV 标识**：前端生成随机唯一 ID 存储在 localStorage，无 cookie 依赖
- **IP 获取**：支持 Cloudflare CF-Connecting-IP、X-Forwarded-For 等代理 IP 识别
- **性能优化**：
  - WAL 模式提升 SQLite 写入性能
  - 索引优化查询速度
  - 异步图片请求方式，不阻塞页面加载

## 🚨 注意事项
1. 确保 `stats.db` 文件所在目录有可写权限
2. 建议将 `stats.php` 放在非公开目录或修改文件名，提高安全性
3. 定期备份 `stats.db` 文件，防止数据丢失
4. 统计代码需在支持 JavaScript 的环境下运行，纯静态页面/无 JS 环境会使用 IP+UA+日期作为弱 UV 标识

## 🛡️ 安全建议
- 定期修改管理员密码
- 限制后台访问 IP（可通过 .htaccess 或服务器配置实现）
- 不要将统计接口暴露给公网滥用（工具已做机器人过滤）

## 📄 开源协议
本项目基于 MIT 协议开源，你可以自由修改、分发和商用，保留原作者信息即可。

## 🐞 常见问题
### Q1: 统计数据不更新？
A1: 检查：
- 集成代码是否正确添加到页面
- 浏览器控制台是否有 JS 报错
- `stats.db` 文件是否有可写权限
- 是否被广告拦截插件屏蔽了统计请求

### Q2: UV 统计不准确？
A2: UV 基于 localStorage 存储的唯一 ID，以下情况会影响准确性：
- 用户清除浏览器缓存/本地存储
- 隐私模式/无痕浏览
- 多浏览器/多设备访问

### Q3: 如何清空所有统计数据？
A3: 删除服务器上的 `stats.db` 文件，重新访问工具初始化即可。

## 💻 开发维护
- 项目地址：https://github.com/7133017/Simple-Stats-Tool
- 如有问题或建议，欢迎提交 Issue 或 Pull Request
