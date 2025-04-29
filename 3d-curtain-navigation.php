<?php
/*
Plugin Name: 3D Curtain Navigation
Plugin URI: https://github.com/stronganchor/3d-curtain-navigation/
Description: Adds a 3D curtain-like page transition effect by animating full-screen sections using GSAP.  
             Now supports scroll-scrubbed transitions whose progress is driven by actual scroll.
Version: 1.11
Author: Strong Anchor Tech
Author URI: https://stronganchortech.com
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 1) Rewrite internal nav menu links to hashed anchors
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
        $atts['href']          = '#' . sanitize_html_class( $slug );
    }
    return $atts;
}

// 2) Enqueue GSAP, ScrollTrigger + inline CSS & JS
add_action( 'wp_enqueue_scripts', 'dcn_enqueue_assets' );
function dcn_enqueue_assets() {
    // Styles
    wp_register_style( 'dcn-style', false );
    wp_enqueue_style(  'dcn-style' );
    wp_add_inline_style( 'dcn-style', dcn_inline_css() );

    // GSAP core + ScrollTrigger
    wp_enqueue_script( 'dcn-gsap',         'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js', [], '3.12.2', true );
    wp_enqueue_script( 'dcn-scrolltrigger','https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/ScrollTrigger.min.js', ['dcn-gsap'], '3.12.2', true );

    // Main script
    wp_register_script( 'dcn-script', false, ['dcn-gsap','dcn-scrolltrigger'], null, true );
    wp_enqueue_script(  'dcn-script' );
    wp_add_inline_script( 'dcn-script', dcn_inline_js() );
}

function dcn_inline_css() {
    return <<<CSS
html, body {
  margin: 0;
  height: 100%;
}
.dcn-wrapper {
  position: relative;
  width: 100%;
  height: 100vh;
  overflow-y: scroll;
  overflow-x: hidden;
  perspective: 1000px;
  perspective-origin: center center;
}
/* keep nav fixed */
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
    // gather sections in DOM order
    var sections = {}, order = [];
    document.querySelectorAll('.dcn-section').forEach(function(sec, idx){
      var id = sec.id || 'home';
      sections[id] = sec;
      order.push(id);
      if(idx === 0) sec.classList.add('current');
    });
    var curIndex = 0,
        total    = order.length,
        wrapper  = document.querySelector('.dcn-wrapper');

    // build one master timeline whose total length = total-1 transitions
    var tl = gsap.timeline({
      scrollTrigger: {
        scroller: wrapper,
        trigger:  wrapper,
        start:    'top top',
        end:      () => window.innerHeight * (total - 1),
        scrub:    true,
        pin:      true,
        snap:     1 / (total - 1),
      }
    });

    // for each adjacent pair, add two tweens at sequential positions
    for (let i = 0; i < total - 1; i++) {
      let cur = sections[ order[i] ],
          nxt = sections[ order[i+1] ];
      tl.to(cur, {
           z:       300,
           opacity: 0,
           duration: 1,
           ease:    'power2.in'
         }, i)
        .fromTo(nxt,
           { z: -300, opacity: 0 },
           { z: 0,   opacity: 1, duration: 1, ease: 'power2.out' },
           i
         );
    }

    // update .current class & URL hash when crossing segment midpoints
    ScrollTrigger.create({
      scroller: wrapper,
      trigger:  wrapper,
      start:    'top top',
      end:      () => window.innerHeight * (total - 1),
      scrub:    true,
      onUpdate: self => {
        let index = Math.round( self.progress * (total - 1) );
        if ( index !== curIndex ) {
          sections[ order[curIndex] ].classList.remove('current');
          sections[ order[index]   ].classList.add('current');
          curIndex = index;
          history.replaceState(null, '', '#' + order[index]);
        }
      }
    });

    // keep click-nav working
    document.querySelectorAll('.dcn-nav a').forEach(function(link){
      let target = link.getAttribute('href').replace('#',''),
          orig   = link.dataset.dcnUrl;
      if (!(target in sections)) return;
      link.addEventListener('click', function(e){
        e.preventDefault();
        // scroll wrapper to the correct segment
        let idx = order.indexOf(target);
        wrapper.scrollTo({ top: idx * window.innerHeight, behavior: 'smooth' });
      });
    });
  });
})();
JS;
}

// 3) Shortcode to output nav + sections (unchanged)
add_shortcode('dcn_sections', 'dcn_render_sections');
function dcn_render_sections($atts) {
  $atts = shortcode_atts(['menu'=>'primary'], $atts, 'dcn_sections');
  $locs = get_nav_menu_locations();
  if ( empty($locs[$atts['menu']]) ) {
    return '';
  }
  $menu  = wp_get_nav_menu_object($locs[$atts['menu']]);
  $items = wp_get_nav_menu_items($menu->term_id);

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
    $slug = sanitize_html_class(get_post_field('post_name',$pid) ?: 'home');
    if ( class_exists('Elementor\Plugin')
      && \Elementor\Plugin::instance()->db->is_built_with_elementor($pid)
    ) {
      $content = \Elementor\Plugin::instance()
                   ->frontend
                   ->get_builder_content_for_display($pid);
    } else {
      $post    = get_post($pid);
      $content = apply_filters('the_content',$post->post_content);
    }
    $out .= "<div id=\"{$slug}\" class=\"dcn-section\">{$content}</div>\n";
  }

  $out .= '</div>';
  return $out;
}
