<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * TtsVoice - 版本兼容层
 *
 * Typecho 1.2 用下划线类名（Typecho_Db、Helper、Widget_User），
 * Typecho 1.3 全面换成命名空间（Typecho\Db、Utils\Helper、Widget\User），
 * 并且 1.3 不再识别 1.2 风格的插件类，会直接报「插件的配置信息没有找到」。
 *
 * 本文件做的事：
 *   1. 把当前版本的类互相 class_alias 成另一套名字，业务代码只写一套即可；
 *   2. 把数据库排序 / 读写常量统一成 TTSV_* 常量；
 *   3. 提供 Throwable 安全的异常构造。
 *
 * 这样同一份插件代码可以同时跑在 1.2 和 1.3 上。
 */

if (defined('TTSVOICE_COMPAT_LOADED')) {
    return;
}

define('TTSVOICE_COMPAT_LOADED', true);

/**
 * 判断是否为 Typecho 1.3+（命名空间版）
 *
 * @return bool
 */
function ttsv_is_modern()
{
    return interface_exists('Typecho\\Plugin\\PluginInterface');
}

/**
 * 需要互相映射的类名：新版 => 旧版
 *
 * @return array
 */
function ttsv_alias_map()
{
    return array(
        // 数据库
        'Typecho\\Db'        => 'Typecho_Db',
        'Typecho\\Db\\Query' => 'Typecho_Db_Query',

        // 核心
        'Typecho\\Widget'  => 'Typecho_Widget',
        'Typecho\\Request' => 'Typecho_Request',
        'Typecho\\Cookie'  => 'Typecho_Cookie',
        'Typecho\\Common'  => 'Typecho_Common',
        'Typecho\\Session' => 'Typecho_Session',

        // 插件与异常
        'Typecho\\Plugin'           => 'Typecho_Plugin',
        'Typecho\\Plugin\\Exception' => 'Typecho_Plugin_Exception',

        // 后台与 Helper
        'Utils\\Helper'    => 'Helper',
        'Widget\\User'     => 'Widget_User',
        'Widget\\Options'  => 'Widget_Options',

        // 配置面板表单元素（插件实际用到 Text / Radio / Textarea / Select）
        'Typecho\\Widget\\Helper\\Form'                    => 'Typecho_Widget_Helper_Form',
        'Typecho\\Widget\\Helper\\Form\\Element'         => 'Typecho_Widget_Helper_Form_Element',
        'Typecho\\Widget\\Helper\\Form\\Element\\Text'   => 'Typecho_Widget_Helper_Form_Element_Text',
        'Typecho\\Widget\\Helper\\Form\\Element\\Radio'  => 'Typecho_Widget_Helper_Form_Element_Radio',
        'Typecho\\Widget\\Helper\\Form\\Element\\Textarea' => 'Typecho_Widget_Helper_Form_Element_Textarea',
        'Typecho\\Widget\\Helper\\Form\\Element\\Select'  => 'Typecho_Widget_Helper_Form_Element_Select',

        // 任意 HTML 布局块（1.6.0 起用于「按服务切换设置项」的内联脚本）
        'Typecho\\Widget\\Helper\\Layout'                 => 'Typecho_Widget_Helper_Layout',
    );
}

/**
 * 建立双向别名
 *
 * @return void
 */
function ttsv_build_aliases()
{
    $map     = ttsv_alias_map();
    $modern  = ttsv_is_modern();
    $forward = $modern; // 新版 -> 旧版

    foreach ($map as $newName => $oldName) {
        if ($forward) {
            $from = $newName;
            $to   = $oldName;
        } else {
            $from = $oldName;
            $to   = $newName;
        }

        if (ttsv_name_taken($to)) {
            continue;
        }

        if (ttsv_name_taken($from)) {
            @class_alias($from, $to);
        }
    }

    // 插件接口也要双向打通：业务代码统一 implements Typecho_Plugin_Interface
    if ($modern) {
        if (!interface_exists('Typecho_Plugin_Interface')) {
            @class_alias('Typecho\\Plugin\\PluginInterface', 'Typecho_Plugin_Interface');
        }
    } else {
        if (!interface_exists('Typecho\\Plugin\\PluginInterface')) {
            @class_alias('Typecho_Plugin_Interface', 'Typecho\\Plugin\\PluginInterface');
        }
    }
}

/**
 * 某个类名 / 接口名是否已存在（类、接口、trait 都算）
 *
 * 这里必须允许自动加载：compat.php 执行时，Typecho 的 Db、Helper
 * 等类往往还没被加载，如果传 autoload = false 会误判为「不存在」，
 * 导致别名建不起来，后续代码直接报类找不到。
 *
 * @param string $name
 * @return bool
 */
function ttsv_name_taken($name)
{
    return class_exists($name) || interface_exists($name) || trait_exists($name);
}

/**
 * 读取类常量，取不到就用兜底值
 *
 * 1.3 的数据库常量可能被调整过，这里保证一定能拿到可用的值。
 *
 * @param string $name
 * @param mixed  $default
 * @return mixed
 */
function ttsv_db_const($name, $default)
{
    foreach (array('Typecho\\Db', 'Typecho_Db') as $class) {
        if (defined($class . '::' . $name)) {
            return constant($class . '::' . $name);
        }
    }

    return $default;
}

ttsv_build_aliases();

if (!defined('TTSV_SORT_DESC')) {
    // 1.2 里 SORT_DESC 的值本身就是字符串 DESC，所以兜底值直接写死即可
    define('TTSV_SORT_DESC', ttsv_db_const('SORT_DESC', 'DESC'));
    define('TTSV_SORT_ASC', ttsv_db_const('SORT_ASC', 'ASC'));
    define('TTSV_DB_WRITE', ttsv_db_const('WRITE', 1));
    define('TTSV_DB_READ', ttsv_db_const('READ', 2));
    define('TTSV_DB_SELECT', ttsv_db_const('SELECT', 'SELECT'));
}

if (!function_exists('ttsv_db')) {
    /**
     * 获取数据库实例（兼容两个版本）
     *
     * @return object
     */
    function ttsv_db()
    {
        if (class_exists('Typecho\\Db')) {
            return call_user_func(array('Typecho\\Db', 'get'));
        }

        return call_user_func(array('Typecho_Db', 'get'));
    }
}

if (!function_exists('ttsv_options')) {
    /**
     * 获取站点配置对象
     *
     * @return object
     */
    function ttsv_options()
    {
        if (class_exists('Utils\\Helper')) {
            return call_user_func(array('Utils\\Helper', 'options'));
        }

        return call_user_func(array('Helper', 'options'));
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
