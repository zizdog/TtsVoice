# TtsVoice 主题调用指南

面向**主题开发者 / 想改播放器外观的站长**。

如果你只是想「装上就能用」，看 `README.md` 就够，**不用改主题**——
插件默认会在文章末尾自动插入播放器。

这份文档解决的是另一类需求：

- 播放器没出现（主题没调 `footer()`）
- 想把播放器放到文章**顶部**而不是底部
- 想用自己的 HTML 结构 / 样式
- 想在侧边栏、归档页做一个「本文可朗读」的小入口

---

## 0. 三种用法，按改动量从小到大

| 方式 | 改动 | 适用场景 |
|---|---|---|
| **① 自动插入** | 无需改主题 | 默认，主题 footer.php 里有 `$this->footer()` |
| **② 手动调用** | 主题模板加 1 行 | 想改位置（如文章顶部），但沿用内置播放器 |
| **③ 数据调用** | 自己写 HTML | 想完全自定义结构与样式 |

**选哪个？** 先试 ①；播放器没出现就用 ②；还不满意再用 ③。

---

## 1. 方式①：自动插入（默认）

什么都不用做。插件挂在 `Widget_Archive::footer` 上。

**前提条件**：主题的 `footer.php` 里有 `$this->footer()`。绝大多数主题都有。

**验证**：打开任意一篇**已生成音频**的文章，翻到底部看有没有播放条。

### 如果没出现

大概率是主题没调 `$this->footer()`。两种解法：

- **解法 A**：后台 → 插件设置 → 「播放器挂载方式」改为**手动调用**，然后按方式②在 `post.php` 加一行
- **解法 B**：在主题 `footer.php` 里补上 `<?php $this->footer(); ?>`

不确定是哪种？上传 `TtsVoice/doctor.php` 到站点根目录访问，
看「播放器挂载方式」和「当前文章」两行。

---

## 2. 方式②：手动调用（改位置）

### 第一步：关掉自动插入

后台 → 插件设置 → **播放器挂载方式** → 选「手动调用」

> 不关会出现两个播放器。

### 第二步：在主题里加一行

编辑主题的 `post.php`，想放哪就放哪：

```php
<?php TtsVoice_Plugin::renderPlayer(); ?>
```

### 常见位置

**文章标题下方、正文上方**（推荐，访客一眼能看到）：

```php
<article class="post">
    <h1 class="post-title"><?php $this->title(); ?></h1>

    <?php TtsVoice_Plugin::renderPlayer(); ?>   <!-- ← 放这里 -->

    <div class="post-content">
        <?php $this->content(); ?>
    </div>
</article>
```

**正文末尾**：

```php
    <div class="post-content">
        <?php $this->content(); ?>
    </div>

    <?php TtsVoice_Plugin::renderPlayer(); ?>   <!-- ← 放这里 -->
</article>
```

**只在文章页显示**（页面类型判断可省略，`renderPlayer()` 内部已按「显示范围」设置过滤）：

```php
<?php if ($this->is('post')): ?>
    <?php TtsVoice_Plugin::renderPlayer(); ?>
<?php endif; ?>
```

### 不用自带样式

想完全自己写 CSS：

```php
<?php TtsVoice_Plugin::renderPlayer(array('css' => false)); ?>
```

---

## 3. 方式③：数据调用（完全自定义）

### 拿到数据

```php
<?php $tts = TtsVoice_Plugin::player(); ?>
```

`player()` 返回：

| 字段 | 类型 | 说明 |
|---|---|---|
| `ready` | bool | **是否可播放**。为 false 时不要渲染任何东西 |
| `cid` | int | 文章 ID |
| `audioUrl` | string | 音频地址 |
| `chapters` | array | 章节 `[{title, start}]`，`start` 单位秒 |
| `duration` | float | 时长（秒） |
| `size` | int | 文件字节数 |
| `html` | string | 现成的播放器 HTML（含 `<audio>`） |
| `css` | string | 样式 `<link>` 片段 |
| `js` | string | 脚本 `<script>` 片段，**必须输出一次** |

### 完整示例：自定义结构

```php
<?php $tts = TtsVoice_Plugin::player(); ?>
<?php if ($tts['ready']): ?>

<?php echo $tts['css']; ?>

<div class="my-audio">
    <div class="my-audio-head">
        <span class="my-audio-label">听这篇文章</span>
        <span class="my-audio-dur"><?php echo round($tts['duration']); ?> 秒</span>
    </div>

    <!-- 最简单的原生播放器 -->
    <audio controls preload="metadata" src="<?php echo $tts['audioUrl']; ?>"></audio>

    <?php if ($tts['chapters']): ?>
    <div class="my-chapters">
        <?php foreach ($tts['chapters'] as $ch): ?>
        <span><?php echo htmlspecialchars($ch['title']); ?></span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <a href="<?php echo $tts['audioUrl']; ?>" download>下载 mp3</a>
</div>

<?php endif; ?>
```

> **注意**：上面用了原生 `<audio controls>`，所以**不需要**输出 `$tts['js']`
> ——那个 JS 是为内置播放器写的（进度条拖拽、倍速、章节高亮）。

### 用内置结构，只要数据

反过来也行：结构用现成的，数据自己取：

```php
<?php $tts = TtsVoice_Plugin::player(); ?>
<?php if ($tts['ready']): ?>
    <div class="my-wrapper">
        <?php echo $tts['css']; ?>
        <?php echo $tts['html']; ?>   <!-- 内置播放器 -->
        <?php echo $tts['js']; ?>     <!-- 必须有，否则点不动 -->
    </div>
<?php endif; ?>
```

`$tts['html']` 与方式② `renderPlayer()` 输出的**完全一致**，
两者共用同一段渲染代码，不会出现「两种写法结构不一样」。

### 内置播放器的 DOM 结构

想改样式就得知道结构。这是 `$tts['html']` 的完整骨架：

```html
<div class="ttsv-player" id="ttsv-player" data-cid="101">
    <div class="ttsv-bar">
        <button class="ttsv-btn ttsv-play" id="ttsv-play">
            <span class="ttsv-icon-play">▶</span>
            <span class="ttsv-icon-pause">❚❚</span>
        </button>

        <div class="ttsv-track" id="ttsv-track">
            <div class="ttsv-loaded" id="ttsv-loaded"></div>
            <div class="ttsv-played" id="ttsv-played"></div>
            <div class="ttsv-thumb"  id="ttsv-thumb"></div>
        </div>

        <span class="ttsv-time">
            <span id="ttsv-current">0:00</span> / <span id="ttsv-total">0:00</span>
        </span>

        <button class="ttsv-btn ttsv-rate" id="ttsv-rate">1.0×</button>

        <button class="ttsv-btn ttsv-mute" id="ttsv-mute">
            <span class="ttsv-icon-vol">🔊</span>
            <span class="ttsv-icon-muted">🔇</span>
        </button>

        <a class="ttsv-btn ttsv-download" href="..." download>↓</a>
    </div>

    <ul class="ttsv-chapters" id="ttsv-chapters">
        <li class="ttsv-chapter">
            <button class="ttsv-chapter-btn" data-start="0.0">章节标题</button>
        </li>
    </ul>

    <audio id="ttsv-audio" preload="metadata" src="..."></audio>
</div>
```

**JS 依赖的 ID 不能改**：`ttsv-player` `ttsv-audio` `ttsv-play` `ttsv-track`
`ttsv-loaded` `ttsv-played` `ttsv-thumb` `ttsv-current` `ttsv-total`
`ttsv-rate` `ttsv-mute`。

**class 名可以随便改**——JS 只按 ID 取元素，样式靠 class。
所以最省事的自定义方式是：**保留 id 和 data 属性，class 全换成自己的，然后自己写 CSS**。

---

## 4. 自定义样式

### 用 CSS 变量（最快）

内置样式全部走 CSS 变量，覆盖一个就能换整套配色：

```css
.ttsv-player {
    --ttsv-bg: #fff;            /* 背景 */
    --ttsv-fg: #333;            /* 文字 */
    --ttsv-muted: #999;         /* 次要文字 */
    --ttsv-accent: #e9546b;     /* 主色：进度条、播放按钮 */
    --ttsv-track: #eee;         /* 进度条底 */
    --ttsv-line: #eee;          /* 分隔线 */
}
```

深色模式已在 `player.css` 里配好（`prefers-color-scheme`），
想覆盖深色值再包一层：

```css
@media (prefers-color-scheme: dark) {
    .ttsv-player {
        --ttsv-bg: #222;
        --ttsv-accent: #ff7a8a;
    }
}
```

### 彻底重写

```php
<?php TtsVoice_Plugin::renderPlayer(array('css' => false)); ?>
```

然后在主题 CSS 里写完整的 `.ttsv-*` 规则。参考 `assets/player.css`（221 行）。

---

## 5. 其他可调用方法

### 判断某篇文章有没有音频

```php
<?php if (TtsVoice_Plugin::player(123)['ready']): ?>
    <!-- 有 -->
<?php endif; ?>
```

传具体 cid 可以查任意文章，不传则取当前文章。

### 取任务状态

```php
<?php $job = TtsVoice_Plugin::job($this->cid); ?>

<?php if ($job): ?>
    状态：<?php echo TtsVoice_Plugin::statusText($job['status']); ?>
    <!-- 未生成 / 排队中 / 合成中 / 下载中 / 已就绪 / 失败 -->
<?php endif; ?>
```

### 在列表页 / 归档页批量显示

```php
<?php while ($this->next()): ?>
    <?php $job = TtsVoice_Plugin::job($this->cid); ?>
    <?php if ($job && 'ready' === $job['status']): ?>
        <span class="has-audio" title="本文可朗读">🎧</span>
    <?php endif; ?>
<?php endwhile; ?>
```

`job()` 每次调用查一次库。列表页文章多的话建议用 `jobs()` 批量取：

```php
<?php
// 先收集所有 cid
$cidList = array();
while ($this->next()) {
    $cidList[] = $this->cid;
}

// 一次查完
$all = TtsVoice_Plugin::jobs($cidList);

// 再循环渲染（注意要重置指针）
$this->rewind();
while ($this->next()) {
    $job = isset($all[$this->cid]) ? $all[$this->cid] : null;
    // ...
}
?>
```

### 状态列表（后台管理用）

```php
<?php $jobs = TtsVoice_Plugin::allJobs(50); ?>
```

返回按更新时间倒序的前 N 条。

---

## 6. 方法速查

| 方法 | 返回 | 说明 |
|---|---|---|
| `renderPlayer($opts)` | void | 输出内置播放器（含 CSS + JS） |
| `player($cid, $opts)` | array | 数据包，自己渲染时用 |
| `currentCid()` | int | 当前文章 ID，非内容页返回 0 |
| `job($cid)` | array\|null | 单条任务记录 |
| `jobs($cids)` | array | 批量任务，键为 cid |
| `allJobs($limit)` | array | 最近 N 条任务 |
| `statusText($status)` | string | 状态中文文案 |
| `audioUrl($file)` | string | 相对路径 → 完整 URL |
| `assetUrl($file)` | string | 插件资源地址 |

`$opts` 支持：`css => false`（不加载自带样式）。

---

## 7. 在哪里试听

### 后台（最快，推荐先在这里验证）

| 位置 | 入口 |
|---|---|
| **管理 → 朗读** 任务列表 | 状态为绿点时，点「**试听**」 |
| **文章编辑页** | 「朗读音频」区，就绪后出现「**试听**」按钮 |
| **管理 → 文章** | 最后一列「朗读」，绿点表示就绪，点「试听」 |

点试听会在**页面底部弹出一条播放条**（原生 audio 控件，带进度、音量、倍速）。
再点一次「暂停」或点 × 关闭。同一时刻只播放一个音频。

### 前台（访客看到的）

音频就绪后，播放器自动出现在**文章末尾**。

没出现说明主题没调 `footer()`，改用手动调用：

```php
<?php TtsVoice_Plugin::renderPlayer(); ?>
```

---

## 8. 状态圆点配色

文章列表与管理页的状态用圆点表示，hover 显示详情：

| 颜色 | 状态 | 含义 |
|---|---|---|
| 🟢 绿 | ready | 已就绪，可播放 |
| 🔵 蓝（呼吸动画） | queued / running / done | 合成中 |
| 🔴 红 | failed | 失败，hover 显示错误原因 |
| ⚪ 灰 | none | 未生成 |

---

## 9. 常见坑

**播放器出现两次**

自动插入没关。后台 → 插件设置 → 播放器挂载方式 → 手动调用。

**手动调用了但没反应**

检查三件事：

1. 文章是否真的生成了音频（编辑页状态是不是「已就绪」）
2. 插件设置的「显示范围」是否包含当前页面类型
3. `player()` 的 `ready` 是不是 true（用 doctor.php 看）

**音频 404**

文件在 `usr/uploads/tts/{cid}.mp3`。检查：

- 目录是否存在、文件是否真的在
- 站点是否做了静态资源拦截（某些安全插件 / Nginx 规则会挡 mp3）
- 用 doctor.php 看「音频目录」那一项

**章节不显示**

文章要有 Markdown 小标题（`# xxx`），且插件设置里「章节跳转」是「显示」。

**想让某篇文章不显示播放器**

当前没有按文章开关。变通做法：在主题里判断：

```php
<?php if (!$this->fields->noAudio): ?>
    <?php TtsVoice_Plugin::renderPlayer(); ?>
<?php endif; ?>
```

然后在不想显示的文章里加自定义字段 `noAudio = 1`。

---

## 10. 完整示例文件

改主题最省事的办法，是把 `post.php` 复制一份改。核心结构：

```php
<?php $this->need('header.php'); ?>

<article class="post">
    <h1><?php $this->title(); ?></h1>

    <?php TtsVoice_Plugin::renderPlayer(); ?>   <!-- 播放器 -->

    <div class="post-content"><?php $this->content(); ?></div>
</article>

<?php $this->need('comments.php'); ?>
<?php $this->need('footer.php'); ?>
```

别忘了去后台把「播放器挂载方式」改成「手动调用」。
