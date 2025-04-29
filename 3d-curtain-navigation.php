<?php
/*
Plugin Name: 3D Curtain Navigation
Plugin URI: https://github.com/stronganchor/3d-curtain-navigation/
Description: Adds a 3D curtain-like page transition effect by animating full-screen sections using GSAP. Includes a shortcode to auto-render sections & navigation so no manual markup is required, and supports Elementor-built pages.
Version: 1.4
Author: Strong Anchor Tech
Author URI: https://stronganchortech.com
*/

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Rewrite internal nav menu links to hashed anchors
 */
add_filter( 'nav_menu_link_attributes', 'dcn_filter_menu_links', 10, 4 );
function dcn_filter_menu_links( $atts, $item, $args, $depth ) {
    if ( empty( $atts['href'] ) ) return $atts;
    if ( strpos( $atts['href'], '#' ) === 0 ) return $atts;

    $home = home_url();
    $href = $atts['href'];
    if ( strpos( $href, $home ) === 0 || strpos( $href, '/' ) === 0 ) {
        $url  = ( strpos( $href, '/' ) === 0 ) ? $home . $href : $href;
        $path = parse_url( $url, PHP_URL_PATH );
        $slug = trim( $path, '/' ) ?: 'home';
        $atts['data-dcn-url'] = esc_url( $url );
        $atts['href'] = '#' . sanitize_html_class( $slug );
    }
    return $atts;
}

/**
 * Enqueue GSAP + inline CSS & JS
 */
add_action( 'wp_enqueue_scripts', 'dcn_enqueue_assets' );
function dcn_enqueue_assets() {
    // Base styles
    wp_register_style( 'dcn-style', false );
    wp_enqueue_style( 'dcn-style' );
    wp_add_inline_style( 'dcn-style', dcn_inline_css() );
    // GSAP
    wp_enqueue_script( 'dcn-gsap', 'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js', [], '3.12.2', true );
    // Core script
    wp_register_script( 'dcn-script', false, ['dcn-gsap'], null, true );
    wp_enqueue_script( 'dcn-script' );
    wp_add_inline_script( 'dcn-script', dcn_inline_js() );
}

function dcn_inline_css() {
    return <<<CSS
html, body { height: 100%; overflow: hidden; margin: 0; perspective: 800px; }
.dcn-nav { position: fixed; top: 0; width: 100%; z-index: 999; }
.dcn-section { position: absolute; top: 0; left: 0; width: 100%; height: 100%; transform-style: preserve-3d; opacity: 0; }
.dcn-section.current { opacity: 1; }
CSS;
}

function dcn_inline_js() {
    return <<<'JS'
(function(){
  document.addEventListener('DOMContentLoaded', function(){
    var sections = {};
    document.querySelectorAll('.dcn-section').forEach(function(sec, idx){
      var id = sec.id || 'home';
      sections[id] = sec;
      if(idx === 0) sec.classList.add('current');
    });
    document.querySelectorAll('.dcn-nav a').forEach(function(link){
      var target = link.getAttribute('href').replace('#','');
      if(!sections[target]) return;
      link.addEventListener('click', function(e){
        e.preventDefault();
        var cur = document.querySelector('.dcn-section.current');
        var nxt = sections[target];
        gsap.to(cur, { z: 300, opacity: 0, duration: 1 });
        gsap.fromTo(nxt, { z: -300, opacity: 0 }, { z: 0, opacity: 1, duration: 1,
          onStart: function(){ nxt.classList.add('current'); },
          onComplete: function(){ cur.classList.remove('current'); }
        });
        var dest = link.dataset.dcnUrl || '#'+target;
        history.pushState(null,'',dest);
      });
    });
  });
})();
JS;
}

/**
 * Shortcode: [dcn_sections menu="primary"]
 * Renders navigation + each menu page as full-screen section.
 */
add_shortcode( 'dcn_sections', 'dcn_render_sections' );
function dcn_render_sections( $atts ) {
  $atts = shortcode_atts([ 'menu'=>'primary' ], $atts, 'dcn_sections' );
  $locs = get_nav_menu_locations();
  if ( empty( $locs[ $atts['menu'] ] ) ) return '';
  $menu = wp_get_nav_menu_object( $locs[ $atts['menu'] ] );
  $items = wp_get_nav_menu_items( $menu->term_id );
  // Build nav HTML
  $nav = wp_nav_menu([ 'menu'=>$menu->term_id, 'container'=>'', 'echo'=>false, 'menu_class'=>'dcn-nav' ]);
  $out = $nav;
  // Build sections
  foreach ( $items as $item ) {
    if ( $item->object !== 'page' ) continue;
    $pid  = $item->object_id;
    $slug = sanitize_html_class( get_post_field('post_name',$pid) ?: 'home' );
    // Elementor support
    if ( class_exists('Elementor\Plugin') && \Elementor\Plugin::instance()->db->is_built_with_elementor($pid) ) {
      $content = \Elementor\Plugin::instance()->frontend->get_builder_content_for_display($pid);
    } else {
      $post = get_post($pid);
      $content = apply_filters('the_content',$post->post_content);
    }
    $out .= "<div id=\"{$slug}\" class=\"dcn-section\">{$content}</div>\n";
  }
  return $out;
}
