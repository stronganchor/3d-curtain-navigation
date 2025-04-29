<?php
/*
Plugin Name: 3D Curtain Navigation
Plugin URI: https://github.com/stronganchor/3d-curtain-navigation/
Description: Adds a 3D curtain-like page transition effect by animating full-screen sections using GSAP. Includes a shortcode to auto-render sections & navigation so no manual markup is required, and supports Elementor-built pages. Allows scrolling to cycle through sections with scale-based 3D transitions.
Version: 1.6
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
 * Enqueue GSAP, ScrollTrigger + inline CSS & JS
 */
add_action( 'wp_enqueue_scripts', 'dcn_enqueue_assets' );
function dcn_enqueue_assets() {
    // Base styles
    wp_register_style( 'dcn-style', false );
    wp_enqueue_style( 'dcn-style' );
    wp_add_inline_style( 'dcn-style', dcn_inline_css() );
    // GSAP core + ScrollTrigger
    wp_enqueue_script( 'dcn-gsap', 'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js', [], '3.12.2', true );
    wp_enqueue_script( 'dcn-scrolltrigger', 'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/ScrollTrigger.min.js', ['dcn-gsap'], '3.12.2', true );
    // Core script
    wp_register_script( 'dcn-script', false, ['dcn-gsap','dcn-scrolltrigger'], null, true );
    wp_enqueue_script( 'dcn-script' );
    wp_add_inline_script( 'dcn-script', dcn_inline_js() );
}

function dcn_inline_css() {
    return <<<CSS
html, body { height: 100%; overflow: hidden; margin: 0; perspective: 1000px; }
.dcn-nav { position: fixed; top: 0; width: 100%; z-index: 999; }
.dcn-section { position: absolute; top: 0; left: 0; width: 100%; height: 100%; transform-style: preserve-3d; transform-origin: center center; opacity: 0; }
.dcn-section.current { opacity: 1; }
CSS;
}

function dcn_inline_js() {
    return <<<'JS'
(function(){
  gsap.registerPlugin(ScrollTrigger);
  document.addEventListener('DOMContentLoaded', function(){
    var sections = {}, order = [];
    document.querySelectorAll('.dcn-section').forEach(function(sec, idx){
      var id = sec.id || 'home';
      sections[id] = sec;
      order.push(id);
      if(idx === 0) sec.classList.add('current');
    });
    var curIndex = 0, animating = false;
    function goTo(index, url) {
      if(animating || index===curIndex || index<0 || index>=order.length) return;
      animating = true;
      var cur = sections[ order[curIndex] ];
      var nxt = sections[ order[index] ];
      // Animate current: scale up and fade out
      gsap.to(cur, { scale: 1.3, opacity: 0, duration: 1, ease: 'power2.in' });
      // Animate next: start slightly smaller, fade in and scale to normal
      gsap.fromTo(nxt,
        { scale: 0.8, opacity: 0 },
        { scale: 1, opacity: 1, duration: 1, ease: 'power2.out',
          onStart: function(){ nxt.classList.add('current'); },
          onComplete: function(){ cur.classList.remove('current'); animating=false; }
        }
      );
      curIndex = index;
      history.pushState(null,'', url || '#'+order[index] );
    }
    // Click bindings
    document.querySelectorAll('.dcn-nav a').forEach(function(link){
      var target = link.getAttribute('href').replace('#','');
      var original = link.dataset.dcnUrl;
      if(!(target in sections)) return;
      link.addEventListener('click', function(e){
        e.preventDefault();
        var idx = order.indexOf(target);
        goTo(idx, original);
      });
    });
    // Wheel/scroll bindings
    window.addEventListener('wheel', function(e){
      if(e.deltaY>10) goTo(curIndex+1);
      else if(e.deltaY<-10) goTo(curIndex-1);
    }, { passive: true });
    // Keyboard arrows
    window.addEventListener('keydown', function(e){
      if(e.key==='ArrowDown'||e.key==='PageDown') { e.preventDefault(); goTo(curIndex+1); }
      if(e.key==='ArrowUp'||e.key==='PageUp') { e.preventDefault(); goTo(curIndex-1); }
    });
    // Optional: ScrollTrigger snap
    ScrollTrigger.create({
      start: 0,
      end: () => window.innerHeight*(order.length-1),
      snap: {
        snapTo: i => Math.round(i/(window.innerHeight))*window.innerHeight,
        duration: 1
      }
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
