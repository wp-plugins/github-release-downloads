<?php
/**
 * Plugin Name: GitHub Release Downloads
 * Plugin URI: http://ivanrf.com/github-release-downloads/
 * Description: Get the download count, links and more information for releases of GitHub repositories.
 * Version: 1.0.0
 * Author: Ivan Ridao Freitas
 * Author URI: http://ivanrf.com/
 * Text Domain: github-release-downloads
 * License: GPL2
 */
 
/*  Copyright 2015  Ivan Ridao Freitas

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

// Make sure we don't expose any info if called directly
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

//** Add plugin shortcodes **//
add_shortcode( 'grd_count', 'grd_download_count_func' );
add_shortcode( 'grd_list', 'grd_download_list_func' );
add_shortcode( 'grd_latest_version', 'grd_latest_version_func' );

function grd_download_count_func( $atts ) {
    $releases = get_release_contents($atts);
    if ($releases !== false) {
        $total_downloads = 0;
        foreach ($releases as $release)
            $total_downloads += get_release_download_count($release);
        return $total_downloads;
    }
    return grd_error_msg( $atts );
}

function get_release_download_count( $release ) {
    $total_downloads = 0;
    foreach ($release->assets as $asset)
        $total_downloads += $asset->download_count;
    return $total_downloads;
}

function grd_download_list_func( $atts ) {
    $a = shortcode_atts( array(
        'hide_size' => false,
        'hide_downloads' => false,
        'downloads_suffix' => ' downloads',
    ), $atts );
    $a['hide_size'] = filter_var( $a['hide_size'], FILTER_VALIDATE_BOOLEAN );
    $a['hide_downloads'] = filter_var( $a['hide_downloads'], FILTER_VALIDATE_BOOLEAN );
    
    $releases = get_release_contents($atts);
    if ($releases !== false) {
        $html = '';
        foreach ($releases as $release)
            $html .= get_release_download_list($release, $a['hide_size'], $a['hide_downloads'], $a['downloads_suffix']);
        return $html;
    }
    return grd_error_msg( $atts );
}

function get_release_download_list( $release, $hide_size, $hide_downloads, $downloads_suffix ) {
    $html = '<h2 class="release-downloads-header">' . $release->name . '</h2>';
    $html .= '<ul class="release-downloads">';
    foreach ($release->assets as $asset) {
        $html .= '<li>';
        $html .= '<a href="' . $asset->browser_download_url . '" rel="nofollow">';
        $html .= '<strong class="release-name">' . $asset->name . '</strong> ';
        if (!$hide_size)
            $html .= '<small class="release-size">' . formatBytes($asset->size) . '</small> ';
        if (!$hide_downloads)
            $html .= '<small class="release-download-count">' . $asset->download_count . $downloads_suffix . '</small>';
        $html .= '</a>';
        $html .= '</li>';
    }
    $html .= '</ul>';
    
    return $html;
}

function grd_latest_version_func( $atts ) {
    // $atts['latest'] = true; // Unnecessary, this way allows using the cached data
    $atts['tag'] = ''; // Avoid confusion
    $releases = get_release_contents($atts);
    if ($releases !== false) {
        $release = reset($releases); // first array element
        if ($release !== false) {
            $latest_tag = $release->tag_name;
            
            // Remove 'v' from the start, e.g. v1.6.0 => 1.6.0
            $latest_tag = preg_replace('/^v(\d)/', '\1', $latest_tag, 1);
            
            return $latest_tag;
        }
        return grd_no_releases_error_msg();
    }
    return grd_error_msg( $atts );
}

/**
 * Gets repository contents through a connection to the GitHub API.
 *  
 * @param array $atts The attributes passed to the shortcodes.
 */
function get_release_contents( &$atts ) {
    $atts = shortcode_atts( array(
        'user'   => get_option( 'grd_user' ),
        'repo'   => get_option( 'grd_repo' ),
        'latest' => false,
        'tag'    => '',
    ), $atts );
    $atts['latest'] = filter_var( $atts['latest'], FILTER_VALIDATE_BOOLEAN );
    
    $latest = $atts['latest'];
    $tag = $atts['tag'];
    
    $url = "https://api.github.com/repos/" . $atts['user'] . "/" . $atts['repo'] . "/releases";
    if ($latest)
        $url .= "/latest";
    else if (!empty($tag))
        $url .= "/tags/" . $tag;
    
    // Check the cache
    $rel_cache = wp_cache_get($url, 'github-release-downloads');
    if ($rel_cache !== false)
        return $rel_cache;
    
    $res = get_github_contents($url);
    if ($res !== false) {
        if ($latest || !empty($tag))
            $res = '[' . $res . ']'; // Unifies different responses
        // Decode the JSON string
        $releases = json_decode($res);
        
        // Cache the result for future queries
        wp_cache_add($url, $releases, 'github-release-downloads');
        
        return $releases;
    } else
        return $res;
}

function get_github_contents( $url ) {
    $response = wp_remote_get( $url );
    // Check response code
    $response_code = wp_remote_retrieve_response_code( $response );
    if ($response_code == '404') // Not Found
        return false;
    // Get body content
    $response = wp_remote_retrieve_body( $response );
    if (!empty( $response ))
        return $response;
    else
        return false;
    
    /* $context = stream_context_create(array('http' => array(
        'method' => 'GET',
        'header' => 'User-Agent: Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)',
    )));
    
    $res = @file_get_contents($url, false, $context);
    return $res; */
}

function grd_error_msg( $atts ) {
    $msg = "Shortcode Error: ";
    if (empty($atts['user']))
        $msg .= "GitHub username can not be empty";
    else if (empty($atts['repo']))
        $msg .= "GitHub repository name can not be empty";
    else
        $msg .= "GitHub repository not found";
    return $msg;
}

function grd_no_releases_error_msg() {
    return "Shortcode Error: GitHub repository has no releases";
}

function formatBytes($bytes, $precision = 2) { 
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
} 

//** Add plugin administration menu **//
add_action( 'admin_init', 'grd_register_settings' );
add_action( 'admin_menu', 'grd_menu' );

function grd_register_settings() {
    add_option( 'grd_user', '');
    add_option( 'grd_repo', '');
    register_setting( 'grd_settings', 'grd_user' );
    register_setting( 'grd_settings', 'grd_repo' );
}

function grd_menu() {
    add_options_page( 'GitHub Release Downloads Options', 'GitHub Release Downloads', 'manage_options', 'github-release-downloads', 'grd_options' );
}

function grd_options() {
    if ( !current_user_can( 'manage_options' ) )  {
        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
    }
    
?>
<div class="wrap">
    <h2>GitHub Release Downloads Settings</h2>
    <form method="post" action="options.php"> 
        <?php settings_fields( 'grd_settings' ); ?>
            <p>Set values for the GitHub username and the repository name to use by default in the shortcodes.</p>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label for="grd_user">User</label></th>
                    <td><input type="text" id="grd_user" name="grd_user" value="<?php grd_echo_option( 'grd_user' ); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="grd_repo">Repository</label></th>
                    <td><input type="text" id="grd_repo" name="grd_repo" value="<?php grd_echo_option( 'grd_repo' ); ?>" /></td>
                </tr>
            </table>
        <?php submit_button(); ?>
    </form>
    <hr/>
    <h3>Need help?</h3>
    <p>Learn how to use the plugin at <a href="http://ivanrf.com/github-release-downloads/" target="_blank">ivanrf.com</a>.</p>
</div>
<?php
}

function grd_echo_option( $option ) {
    echo esc_attr(get_option( $option ));
}
?>