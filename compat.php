<?php
/**
 * TtsVoice - 兼容层（Typecho 1.3）
 *
 * 1.3 的自动加载器已经会把 `Typecho_Db` / `Widget_User` / 表单元素这类
 * 下划线类名与命名空间类互相 class_alias，所以这里**只需要补它没覆盖的**：
 *   · `Helper`（实际类名是 `Utils\Helper`）
 *   · `Typecho_Plugin_Interface`（业务代码用它 implements）
 *
 * 另外提供三样小工具：数据库实例、异常消息、排序/写入常量。
 * 不再支持 Typecho 1.2（2026-10-28 起，站点固定 1.3.0）。
 *
 * @package TtsVoice
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

if (defined('TTSVOICE_COMPAT_LOADED')) {
    return;
}

define('TTSVOICE_COMPAT_LOADED', true);

/*
 * 【注意顺序】必须先让核心加载真正的类（`Utils\Helper` / `Typecho\Plugin\PluginInterface`），
 * 核心的 autoloader 在加载时**自己就会**建好下划线别名；加载完再判断别名是否已经存在，
 * 否则 `class_alias()` 会因为「名字已被占用」抛 Warning：
 *   Cannot declare class Helper, because the name is already in use
 */
class_exists('Utils\\Helper');
interface_exists('Typecho\\Plugin\\PluginInterface');

if (!class_exists('Helper', false)) {
    class_alias('Utils\\Helper', 'Helper');
}

if (!interface_exists('Typecho_Plugin_Interface', false)) {
    class_alias('Typecho\\Plugin\\PluginInterface', 'Typecho_Plugin_Interface');
}

if (!defined('TTSV_SORT_DESC')) {
    define('TTSV_SORT_DESC', defined('Typecho\\Db::SORT_DESC') ? constant('Typecho\\Db::SORT_DESC') : 'DESC');
}

if (!defined('TTSV_DB_WRITE')) {
    define('TTSV_DB_WRITE', defined('Typecho\\Db::WRITE') ? constant('Typecho\\Db::WRITE') : 2);
}

if (!function_exists('ttsv_db')) {
    /**
     * 数据库实例
     *
     * @return object
     */
    function ttsv_db()
    {
        return call_user_func(array('Typecho\\Db', 'get'));
    }
}

if (!function_exists('ttsv_throwable_message')) {
    /**
     * 安全取异常信息（TypeError / Error 也适用）
     *
     * @param mixed $e
     * @return string
     */
    function ttsv_throwable_message($e)
    {
        if (is_object($e) && method_exists($e, 'getMessage')) {
            return (string) $e->getMessage();
        }

        return is_scalar($e) ? (string) $e : '未知错误';
    }
}
