<?php if (!isset($page_title)) { $page_title = 'ArchiFlow Dashboard'; } ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, shrink-to-fit=no">
<title><?php echo htmlspecialchars($page_title); ?> | ArchiFlow</title>

<!-- Tailwind CSS CDN -->
<script src="https://cdn.tailwindcss.com"></script>

<!-- FontAwesome Icons -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<!-- Google Fonts - Montserrat -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

<!-- Tailwind CSS Configuration -->
<script>
    tailwind.config = {
        theme: {
            extend: {
                fontFamily: {
                    'montserrat': ['Montserrat', 'sans-serif'],
                },
                colors: {
                    'navy': {
                        50: '#f0f9ff',
                        100: '#e0f2fe',
                        200: '#bae6fd',
                        300: '#7dd3fc',
                        400: '#38bdf8',
                        500: '#0ea5e9',
                        600: '#0284c7',
                        700: '#0369a1',
                        800: '#075985',
                        900: '#0c4a6e',
                        950: '#082f49'
                    }
                },
                backgroundImage: {
                    'navy-gradient': 'linear-gradient(135deg, #0c1e3d 0%, #1e40af 25%, #3b82f6 75%, #60a5fa 100%)',
                    'navy-dark': 'linear-gradient(135deg, #0a1628 0%, #0c2340 50%, #1e40af 100%)',
                }
            }
        }
    }
</script>sset($page_title)) { $page_title = 'ArchiFlow Dashboard'; } ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, shrink-to-fit=no">
<title><?php echo htmlspecialchars($page_title); ?> | ArchiFlow</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/auth.css">
<link rel="stylesheet" href="css/layout.css?v=<?php echo time(); ?>">
<style>
/* Ultra-Modern Fallback & Enhancement Styles */
:root {
  --primary-rgb: 14, 165, 233;
  --accent-rgb: 168, 85, 247;
  --glass-alpha: 0.1;
}

/* Enhanced loading animation */
.page-loader {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: var(--gradient-mesh);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9999;
  opacity: 1;
  transition: opacity 0.6s cubic-bezier(0.4, 0, 0.2, 1);
}

.page-loader.fade-out {
  opacity: 0;
  pointer-events: none;
}

.loader-content {
  text-align: center;
  color: var(--gray-700);
}

.loader-spinner {
  width: 60px;
  height: 60px;
  border: 4px solid rgba(var(--primary-rgb), 0.1);
  border-left: 4px solid rgb(var(--primary-rgb));
  border-radius: 50%;
  animation: spin 1s linear infinite;
  margin: 0 auto 1rem;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}

.loader-text {
  font-weight: 500;
  font-size: 0.9rem;
  opacity: 0.8;
}

/* Enhanced header utilities */
header.top-bar,
header.glass-header {
  backdrop-filter: blur(20px);
  background: var(--surface-glass);
  border-bottom: var(--glass-border);
  box-shadow: var(--shadow-md);
  position: sticky;
  top: 0;
  z-index: var(--z-sticky);
}

.top-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: var(--space-4) var(--space-6);
}

/* Enhanced logo styling */
.logo,
.brand-logo {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  font-size: var(--text-xl);
  font-weight: var(--font-bold);
  color: var(--gray-900) !important;
  text-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
  filter: contrast(1.2);
}

.logo-icon {
  font-size: 1.5rem;
  filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.3));
}

.logo-text {
  background: linear-gradient(135deg, #1e40af 0%, #7c3aed 100%);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
  font-weight: var(--font-bold);
  text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

/* Enhanced user box */
.user-box,
.enhanced-user {
  display: flex;
  align-items: center;
  gap: var(--space-4);
}

.user-name {
  font-weight: var(--font-semibold);
  color: var(--gray-900) !important;
  text-shadow: 0 1px 3px rgba(255, 255, 255, 0.9);
  font-size: 1rem;
  filter: contrast(1.3);
}

.logout,
.enhanced-logout {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-2) var(--space-4);
  background: var(--surface-glass);
  border: var(--glass-border);
  border-radius: var(--radius-xl);
  color: #2d3748 !important;
  text-decoration: none;
  font-weight: var(--font-semibold);
  transition: var(--transition-fast);
  box-shadow: var(--shadow-sm);
  text-shadow: 0 1px 2px rgba(255, 255, 255, 0.8);
}

.logout:hover,
.enhanced-logout:hover {
  background: var(--danger-50);
  color: #c53030 !important;
  transform: translateY(-1px);
  box-shadow: var(--shadow-md);
  font-weight: var(--font-bold);
}

/* Enhanced navigation toggle */
.nav-toggle,
.enhanced-toggle {
  display: flex !important; /* Always show toggle button */
  width: 50px;
  height: 50px;
  background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
  border: 3px solid #2563eb;
  border-radius: var(--radius-xl);
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: var(--transition-fast);
  color: #1e40af !important;
  font-size: 1.8rem;
  font-weight: 900 !important;
  box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
  text-shadow: 1px 1px 2px rgba(255, 255, 255, 0.9);
  position: relative;
  z-index: 10000;
}

.nav-toggle:hover,
.enhanced-toggle:hover {
  transform: scale(1.15);
  box-shadow: 0 6px 20px rgba(37, 99, 235, 0.4);
  background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
  color: #1d4ed8 !important;
  border-color: #1d4ed8;
}

.nav-toggle.active,
.enhanced-toggle.active {
  background: var(--primary-100);
  color: #2b6cb0 !important;
  border-color: #2b6cb0;
  box-shadow: var(--shadow-lg);
}

/* Sidebar enhancements */
.sidebar,
.ultra-sidebar {
  position: fixed;
  top: 0;
  left: 0;
  height: 100vh;
  width: 280px;
  background: var(--surface-glass);
  backdrop-filter: blur(32px);
  border-right: var(--glass-border);
  z-index: var(--z-fixed);
  transition: var(--transition-base);
  overflow-y: auto;
  overflow-x: hidden;
  transform: translateX(0);
}

.sidebar-brand,
.enhanced-brand {
  padding: var(--space-6);
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
  text-align: center;
}

.brand-icon {
  display: block;
  font-size: 2.5rem;
  margin-bottom: var(--space-2);
  filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.1));
}

.brand-text {
  display: block;
  font-size: var(--text-2xl);
  font-weight: var(--font-bold);
  background: linear-gradient(135deg, #1e40af 0%, #7c3aed 100%);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
  margin-bottom: var(--space-1);
  filter: contrast(1.2);
}

.brand-subtitle {
  display: block;
  font-size: var(--text-sm);
  color: var(--gray-800);
  font-weight: var(--font-semibold);
  text-shadow: 0 1px 2px rgba(255, 255, 255, 0.8);
}

.sidebar-nav,
.enhanced-nav {
  padding: var(--space-4) 0;
}

.sb-link {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-6);
  color: var(--gray-900) !important;
  text-decoration: none;
  transition: var(--transition-fast);
  font-weight: var(--font-semibold);
  border-left: 3px solid transparent;
  text-shadow: 0 1px 2px rgba(255, 255, 255, 0.9);
  font-size: 0.95rem;
}

.sb-link:hover {
  background: rgba(255, 255, 255, 0.1);
  color: var(--primary-600) !important;
  border-left-color: var(--primary-500);
  transform: translateX(4px);
}

.sb-link.active {
  background: var(--primary-50);
  color: var(--primary-700) !important;
  border-left-color: var(--primary-600);
  box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.1);
}

/* Enhanced sidebar link styling */
.enhanced-link,
.enhanced-sublink {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-6);
  color: var(--gray-200) !important;
  text-decoration: none;
  transition: var(--transition-fast);
  font-weight: var(--font-medium);
  border-left: 3px solid transparent;
  position: relative;
}

.enhanced-link:hover,
.enhanced-sublink:hover {
  background: rgba(255, 255, 255, 0.1);
  color: var(--primary-300) !important;
  border-left-color: var(--primary-400);
  transform: translateX(4px);
}

.enhanced-link.active,
.enhanced-sublink.active {
  background: rgba(var(--primary-rgb), 0.2);
  color: var(--primary-100) !important;
  border-left-color: var(--primary-400);
  box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.2);
}

.enhanced-sublink {
  padding-left: var(--space-12);
  font-size: var(--text-sm);
  opacity: 0.9;
}

.enhanced-sublink:hover {
  opacity: 1;
}

.enhanced-group {
  margin-bottom: var(--space-6);
}

.group-title {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-2) var(--space-6);
  font-size: var(--text-xs);
  font-weight: var(--font-semibold);
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: var(--gray-400);
  margin-bottom: var(--space-2);
}

.link-text {
  flex: 1;
}

/* Enhanced icon visibility */
.enhanced-link span[class^="icon-"],
.enhanced-sublink span[class^="icon-"],
.group-title span[class^="icon-"] {
  width: 20px;
  height: 20px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  flex-shrink: 0;
  opacity: 0.8;
}

.enhanced-link:hover span[class^="icon-"],
.enhanced-sublink:hover span[class^="icon-"] {
  opacity: 1;
  transform: scale(1.1);
}

/* Enhanced responsive navigation */
.nav-toggle {
  display: none;
  width: 40px;
  height: 40px;
  background: var(--surface-glass);
  border: var(--glass-border);
  border-radius: var(--radius-xl);
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: var(--transition-fast);
}

.nav-toggle:hover {
  transform: scale(1.1);
  box-shadow: var(--shadow-md);
}

@media (max-width: 768px) {
  .nav-toggle,
  .enhanced-toggle {
    display: flex;
  }
  
  .sidebar,
  .ultra-sidebar {
    transform: translateX(-100%);
  }
  
  .sidebar.open,
  .ultra-sidebar.open {
    transform: translateX(0);
  }
  
  .main-content {
    margin-left: 0;
  }
}

@media (min-width: 769px) {
  .sidebar,
  .ultra-sidebar {
    transform: translateX(0);
  }
  
  .main-content {
    margin-left: 280px;
  }
}

/* Enhanced accessibility */
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

/* Focus states */
*:focus {
  outline: 2px solid rgba(var(--primary-rgb), 0.6);
  outline-offset: 2px;
}

button:focus,
input:focus,
select:focus,
textarea:focus {
  outline: 2px solid rgba(var(--primary-rgb), 0.6);
  outline-offset: 2px;
}

/* Print styles */
@media print {
  .page-loader,
  .nav-toggle,
  header.top-bar {
    display: none !important;
  }
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function(){
	// Ultra-Modern Dashboard Enhancement Script
	const root = document.documentElement;
	const topBar = document.querySelector('.top-bar');
	const spacer = document.getElementById('header-spacer');
	const nav = document.getElementById('mainNav');
	const toggle = document.querySelector('.nav-toggle');
	const sidebar = document.getElementById('sidebar');
	const loader = document.querySelector('.page-loader');
	
	// Enhanced utility functions
	function syncSpacer(){
		if(!topBar || !spacer) return;
		const h = Math.ceil(topBar.getBoundingClientRect().height);
		spacer.style.height = h + 'px';
		root.style.setProperty('--header-height', h+'px');
	}
	
	function debounce(func, wait) {
		let timeout;
		return function executedFunction(...args) {
			const later = () => {
				clearTimeout(timeout);
				func(...args);
			};
			clearTimeout(timeout);
			timeout = setTimeout(later, wait);
		};
	}
	
	// Enhanced responsive utilities
	const onResize = debounce(syncSpacer, 110);
	window.addEventListener('resize', onResize);
	window.addEventListener('orientationchange', syncSpacer);
	window.addEventListener('load', syncSpacer);
	
	// Enhanced zoom detection with better performance
	let dpr = window.devicePixelRatio;
	const zoomCheck = setInterval(() => {
		if(Math.abs(window.devicePixelRatio - dpr) > 0.02) {
			dpr = window.devicePixelRatio;
			syncSpacer();
			if(window.scrollY < 10) {
				window.scrollTo({top: 0, behavior: 'smooth'});
			}
		}
	}, 350);
	
	// Enhanced observers with error handling
	try {
		if(window.ResizeObserver && topBar) {
			new ResizeObserver(syncSpacer).observe(topBar);
		}
		if(window.MutationObserver && nav) {
			new MutationObserver(syncSpacer).observe(nav, {
				attributes: true,
				attributeFilter: ['class']
			});
		}
	} catch(e) {
		console.warn('Observer not supported:', e);
	}
	
	// Enhanced sidebar & navigation control
	if(toggle && sidebar) {
		console.log('Sidebar toggle initialized');
		
		toggle.addEventListener('click', function(e) {
			e.preventDefault();
			console.log('Toggle clicked');
			
			const isOpen = sidebar.classList.contains('open');
			const nowOpen = !isOpen;
			
			console.log('Current state:', isOpen, 'New state:', nowOpen);
			
			// Toggle classes
			sidebar.classList.toggle('open', nowOpen);
			document.body.classList.toggle('sidebar-open', nowOpen);
			toggle.classList.toggle('active', nowOpen);
			
			// Enhanced accessibility
			sidebar.setAttribute('aria-hidden', nowOpen ? 'false' : 'true');
			toggle.setAttribute('aria-expanded', nowOpen ? 'true' : 'false');
			
			// Direct style manipulation for immediate effect
			const contentWrapper = document.querySelector('.content-wrapper');
			if(nowOpen) {
				sidebar.style.transform = 'translateX(0)';
				if(contentWrapper && window.innerWidth > 768) {
					contentWrapper.style.marginLeft = 'var(--sidebar-width)';
				}
				console.log('Sidebar opened');
			} else {
				sidebar.style.transform = 'translateX(-100%)';
				if(contentWrapper) {
					contentWrapper.style.marginLeft = '0';
				}
				console.log('Sidebar closed');
			}
			
			// Mobile overlay handling
			if(window.innerWidth <= 768) {
				let overlay = document.querySelector('.mobile-menu-overlay');
				if(!overlay && nowOpen) {
					overlay = document.createElement('div');
					overlay.className = 'mobile-menu-overlay';
					overlay.style.cssText = `
						position: fixed;
						top: 0;
						left: 0;
						width: 100%;
						height: 100%;
						background: rgba(0, 0, 0, 0.5);
						backdrop-filter: blur(4px);
						z-index: 1050;
						opacity: 0;
						transition: opacity 0.3s ease;
					`;
					document.body.appendChild(overlay);
					
					// Fade in overlay
					setTimeout(() => overlay.style.opacity = '1', 10);
					
					// Close sidebar when overlay is clicked
					overlay.addEventListener('click', () => {
						sidebar.classList.remove('open');
						document.body.classList.remove('sidebar-open');
						toggle.classList.remove('active');
						sidebar.style.transform = 'translateX(-100%)';
						overlay.style.opacity = '0';
						setTimeout(() => overlay.remove(), 300);
					});
				}
				
				if(overlay && !nowOpen) {
					overlay.style.opacity = '0';
					setTimeout(() => overlay.remove(), 300);
				}
			}
			
			syncSpacer();
		});
		
		// Initialize sidebar state
		console.log('Setting initial sidebar state');
		sidebar.style.transform = 'translateX(-100%)';
		const contentWrapper = document.querySelector('.content-wrapper');
		if(contentWrapper) {
			contentWrapper.style.marginLeft = '0';
		}
	} else {
		console.log('Toggle or sidebar not found', {toggle, sidebar});
	}
	
	// Enhanced page loader with smooth transitions
	if(loader) {
		const hideLoader = () => {
			loader.classList.add('fade-out');
			setTimeout(() => {
				if(loader.parentNode) {
					loader.parentNode.removeChild(loader);
				}
			}, 600);
		};
		
		// Hide loader when everything is ready
		if(document.readyState === 'complete') {
			setTimeout(hideLoader, 300);
		} else {
			window.addEventListener('load', () => setTimeout(hideLoader, 300));
		}
	}
	
	// Enhanced form interactions
	const enhanceForms = () => {
		const inputs = document.querySelectorAll('input, textarea, select');
		inputs.forEach(input => {
			// Add floating label effect
			if(input.placeholder && !input.classList.contains('enhanced')) {
				input.classList.add('enhanced');
				
				// Create floating label if not exists
				if(!input.previousElementSibling || !input.previousElementSibling.classList.contains('floating-label')) {
					const label = document.createElement('label');
					label.className = 'floating-label';
					label.textContent = input.placeholder;
					input.parentNode.insertBefore(label, input);
				}
			}
		});
	};
	
	// Enhanced button effects
	const enhanceButtons = () => {
		const buttons = document.querySelectorAll('button, .btn, input[type="submit"]');
		buttons.forEach(button => {
			if(!button.classList.contains('enhanced')) {
				button.classList.add('enhanced');
				
				// Add ripple effect
				button.addEventListener('click', function(e) {
					const ripple = document.createElement('span');
					const rect = this.getBoundingClientRect();
					const size = Math.max(rect.width, rect.height);
					const x = e.clientX - rect.left - size / 2;
					const y = e.clientY - rect.top - size / 2;
					
					ripple.style.cssText = `
						width: ${size}px;
						height: ${size}px;
						left: ${x}px;
						top: ${y}px;
					`;
					ripple.className = 'ripple-effect';
					
					this.appendChild(ripple);
					setTimeout(() => ripple.remove(), 600);
				});
			}
		});
	};
	
	// Enhanced card animations
	const enhanceCards = () => {
		const cards = document.querySelectorAll('.card, .section-card, .stat-card');
		const observer = new IntersectionObserver((entries) => {
			entries.forEach(entry => {
				if(entry.isIntersecting) {
					entry.target.classList.add('animate-in');
				}
			});
		}, { threshold: 0.1 });
		
		cards.forEach(card => {
			if(!card.classList.contains('enhanced')) {
				card.classList.add('enhanced');
				observer.observe(card);
			}
		});
	};
	
	// Enhanced theme utilities
	const initThemeUtils = () => {
		// Add theme toggle if needed (future enhancement)
		const themeToggle = document.querySelector('[data-theme-toggle]');
		if(themeToggle) {
			themeToggle.addEventListener('click', () => {
				const currentTheme = document.documentElement.getAttribute('data-theme');
				const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
				document.documentElement.setAttribute('data-theme', newTheme);
				localStorage.setItem('theme', newTheme);
			});
		}
		
		// Apply saved theme
		const savedTheme = localStorage.getItem('theme');
		if(savedTheme) {
			document.documentElement.setAttribute('data-theme', savedTheme);
		}
	};
	
	// Enhanced scroll utilities
	const initScrollUtils = () => {
		let ticking = false;
		
		const updateScrollEffects = () => {
			const scrolled = window.scrollY;
			root.style.setProperty('--scroll-y', scrolled + 'px');
			
			// Add/remove scrolled class for header effects
			if(topBar) {
				topBar.classList.toggle('scrolled', scrolled > 20);
			}
			
			ticking = false;
		};
		
		window.addEventListener('scroll', () => {
			if(!ticking) {
				requestAnimationFrame(updateScrollEffects);
				ticking = true;
			}
		});
	};
	
	// Initialize all enhancements
	syncSpacer();
	enhanceForms();
	enhanceButtons();
	if(window.IntersectionObserver) {
		enhanceCards();
	}
	initThemeUtils();
	initScrollUtils();
	
	// Reset navigation scroll
	if(nav) {
		nav.scrollLeft = 0;
	}
	
	// Enhanced error handling
	window.addEventListener('error', (e) => {
		console.warn('Enhancement error:', e.error);
	});
	
	// Enhanced performance monitoring
	if(window.performance && window.performance.mark) {
		window.performance.mark('dashboard-enhanced');
	}
});

// Add CSS for enhanced animations
const enhancementStyles = document.createElement('style');
enhancementStyles.textContent = `
	.ripple-effect {
		position: absolute;
		border-radius: 50%;
		background: rgba(255, 255, 255, 0.6);
		transform: scale(0);
		animation: ripple-animation 0.6s linear;
		pointer-events: none;
	}
	
	@keyframes ripple-animation {
		to {
			transform: scale(4);
			opacity: 0;
		}
	}
	
	.enhanced.animate-in {
		animation: slide-up 0.6s cubic-bezier(0.4, 0, 0.2, 1);
	}
	
	@keyframes slide-up {
		from {
			opacity: 0;
			transform: translateY(20px);
		}
		to {
			opacity: 1;
			transform: translateY(0);
		}
	}
	
	.floating-label {
		position: absolute;
		left: 12px;
		top: 12px;
		color: var(--gray-500);
		font-size: 0.875rem;
		transition: var(--transition-fast);
		pointer-events: none;
		background: var(--surface-primary);
		padding: 0 4px;
		border-radius: 2px;
	}
	
	.enhanced:focus + .floating-label,
	.enhanced:not(:placeholder-shown) + .floating-label {
		transform: translateY(-24px) scale(0.85);
		color: var(--primary-600);
	}
`;
document.head.appendChild(enhancementStyles);
</script>
</head>
<body class="dash-body">
<!-- Ultra-Modern Page Loader -->
<div class="page-loader">
    <div class="loader-content">
        <div class="loader-spinner"></div>
        <div class="loader-text">Loading ArchiFlow...</div>
    </div>
</div>

<?php include __DIR__.'/nav.php'; ?>
<?php include __DIR__.'/sidebar.php'; ?>
<div class="content-wrapper">
