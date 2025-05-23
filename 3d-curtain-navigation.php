<?php
/*
Plugin Name: 3D Curtain Navigation
Plugin URI: https://github.com/stronganchor/3d-curtain-navigation/
Description: Adds a 3D curtain-like page transition effect by animating full-screen sections using GSAP. Includes a shortcode to auto-render sections & navigation so no manual markup is required, and supports Elementor-built pages. Allows scrolling to cycle through sections with true 3D transitions whose speed matches your scroll velocity.
Version: 1.10
Author: Strong Anchor Tech
Author URI: https://stronganchortech.com
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Rewrite internal nav menu links to hashed anchors
add_filter( 'nav_menu_link_attributes', 'dcn_filter_menu_links', 10, 4 );
function dcn_filter_menu_links( $atts, $item, $args, $depth ) {
    if ( empty( $atts['href'] ) || strpos( $atts['href'], '#' ) === 0 ) {
        return $atts;
    }
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

// Enqueue GSAP, ScrollTrigger + inline CSS & JS
add_action( 'wp_enqueue_scripts', 'dcn_enqueue_assets' );
function dcn_enqueue_assets() {
    // Styles
    wp_register_style( 'dcn-style', false );
    wp_enqueue_style( 'dcn-style' );
    wp_add_inline_style( 'dcn-style', dcn_inline_css() );

    // GSAP core + ScrollTrigger
    wp_enqueue_script( 'dcn-gsap', 'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js', [], '3.12.2', true );
    wp_enqueue_script( 'dcn-scrolltrigger', 'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/ScrollTrigger.min.js', ['dcn-gsap'], '3.12.2', true );

    // Main script
    wp_register_script( 'dcn-script', false, ['dcn-gsap', 'dcn-scrolltrigger'], null, true );
    wp_enqueue_script( 'dcn-script' );
    wp_add_inline_script( 'dcn-script', dcn_inline_js() );
}

function dcn_inline_css() {
    return <<<CSS
html, body {
  margin: 0;
  height: 100%;
  overflow: hidden;
}
.dcn-wrapper {
  position: relative;
  width: 100%;
  height: 100vh;
  overflow: hidden;
  perspective: 1000px;
  perspective-origin: center center;
}
.dcn-nav {
  position: fixed;
  top: 0;
  width: 100%;
  z-index: 999;
}
.dcn-section {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  transform-style: preserve-3d;
  transform-origin: center center;
  opacity: 0;
}
.dcn-section.current {
  opacity: 1;
}
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

    var curIndex = 0,
        animating = false;

    // now accepts optional durationOverride
    function goTo(i, url, durationOverride) {
      if (animating || i === curIndex || i < 0 || i >= order.length) return;
      animating = true;

      var cur = sections[order[curIndex]],
          nxt = sections[order[i]],
          dur = (typeof durationOverride === 'number') ? durationOverride : 1,
          tl  = gsap.timeline({
            onComplete: function() {
              cur.classList.remove('current');
              curIndex = i;
              history.pushState(null, '', url || '#' + order[i]);
              animating = false;
            }
          });

      // Exit current: move forward in Z & fade
      tl.to(cur, {
        z:      300,
        opacity: 0,
        duration: dur,
        ease:    'power2.in'
      });

      // Show next immediately
      tl.add(function(){ nxt.classList.add('current'); }, '+=0');

      // Entrance next: move from behind and fade in
      tl.fromTo(nxt,
        { z: -300, opacity: 0 },
        { z:  0,   opacity: 1, duration: dur, ease: 'power2.out' },
        '>-0.1'
      );
    }

    // Click nav
    document.querySelectorAll('.dcn-nav a').forEach(function(link){
      var target = link.getAttribute('href').replace('#',''),
          orig   = link.dataset.dcnUrl;
      if (!(target in sections)) return;
      link.addEventListener('click', function(e){
        e.preventDefault();
        goTo(order.indexOf(target), orig);
      });
    });

    // Wheel → speed-based duration
    window.addEventListener('wheel', function(e){
      var delta = Math.abs(e.deltaY),
          minDur = 0.2,
          maxDur = 1.5,
          // map delta [0 … 2000] → duration [maxDur … minDur]
          dur     = maxDur - ( Math.min(delta,2000) / 2000 ) * (maxDur - minDur);

      if (e.deltaY > 10)    goTo(curIndex + 1, null, dur);
      else if (e.deltaY < -10) goTo(curIndex - 1, null, dur);
    }, { passive:true });

    // Keyboard arrows / page up-down
    window.addEventListener('keydown', function(e){
      if (e.key==='ArrowDown' || e.key==='PageDown') {
        e.preventDefault();
        goTo(curIndex + 1);
      }
      if (e.key==='ArrowUp' || e.key==='PageUp') {
        e.preventDefault();
        goTo(curIndex - 1);
      }
    });

    // Snap-based scrollbar/touch fallback
    ScrollTrigger.create({
      start: 0,
      end: () => window.innerHeight * (order.length - 1),
      snap: {
        snapTo: i => Math.round(i / window.innerHeight) * window.innerHeight
      }
    });
  });
})();
JS;
}

// Shortcode to output nav + sections
add_shortcode('dcn_sections', 'dcn_render_sections');
function dcn_render_sections($atts) {
  $atts = shortcode_atts(['menu' => 'primary'], $atts, 'dcn_sections');
  $locs = get_nav_menu_locations();
  if ( empty($locs[$atts['menu']]) ) {
    return '';
  }
  $menu  = wp_get_nav_menu_object($locs[$atts['menu']]);
  $items = wp_get_nav_menu_items($menu->term_id);

  // wrap everything in our self-contained 3D stage
  $out  = '<div class="dcn-wrapper">';
  $out .= wp_nav_menu([
    'menu'       => $menu->term_id,
    'container'  => '',
    'echo'       => false,
    'menu_class' => 'dcn-nav',
  ]);

  foreach ($items as $item) {
    if ($item->object !== 'page') continue;

    $pid  = $item->object_id;
    $slug = sanitize_html_class(get_post_field('post_name', $pid) ?: 'home');

    if (
      class_exists('Elementor\Plugin')
      && \Elementor\Plugin::instance()->db->is_built_with_elementor($pid)
    ) {
      $content = \Elementor\Plugin::instance()
                   ->frontend
                   ->get_builder_content_for_display($pid);
    } else {
      $post    = get_post($pid);
      $content = apply_filters('the_content', $post->post_content);
    }

    $out .= "<div id=\"{$slug}\" class=\"dcn-section\">{$content}</div>\n";
  }

  $out .= '</div>';
  return $out;
}
