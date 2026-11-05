"""Real HTTP/module-toggle checks against an isolated running WordPress fixture."""
import argparse
import json
import subprocess
import urllib.error
import urllib.request
import xml.etree.ElementTree as ET

parser = argparse.ArgumentParser()
parser.add_argument('--wp-cli', required=True)
parser.add_argument('--wp-root', required=True)
parser.add_argument('--base-url', default='http://127.0.0.1:18079')
args = parser.parse_args()

def wp(*parts):
    # Ignore PHP deprecations in the WP-CLI launcher before WordPress loads;
    # WP_DEBUG restores full reporting for the actual plugin runtime.
    return subprocess.check_output(['php', '-d', 'error_reporting=24575', args.wp_cli, '--path='+args.wp_root, *parts], text=True).strip()

if wp('config', 'get', 'DB_NAME') != 'inklura_seo_audit':
    raise RuntimeError('Requires the isolated inklura_seo_audit database')

checks = 0

def check(condition, message):
    global checks
    checks += 1
    if not condition:
        raise AssertionError(message)

def request(path):
    try:
        with urllib.request.urlopen(args.base_url+path, timeout=15) as response:
            return response.status, response.headers, response.read().decode()
    except urllib.error.HTTPError as error:
        return error.code, error.headers, error.read().decode()

def settings(**values):
    wp('option', 'update', 'inklura_seo_settings', json.dumps(values), '--format=json')

original = {key: wp('option', 'get', key, '--format=json') for key in ['inklura_seo_settings', 'permalink_structure', 'blog_public']}
post = None
try:
    wp('option', 'update', 'blog_public', '1')
    settings()
    post = wp('post', 'create', '--post_title=HTTP SEO fixture', '--post_content='+('été & nature ' * 100), '--post_status=publish', '--porcelain')
    wp('post', 'meta', 'update', post, '_inklura_seo_title', 'HTTP SEO custom title')
    for structure in ['', '/%postname%/']:
        wp('rewrite', 'structure', structure)
        index_path = '/inklura-sitemap.xml' if structure else '/?inklura_seo_sitemap=1'
        status, headers, body = request(index_path)
        check(status == 200 and 'application/xml' in headers['Content-Type'], 'Sitemap index not served as XML')
        tree = ET.fromstring(body)
        locs = [node.text for node in tree.findall('.//{http://www.sitemaps.org/schemas/sitemap/0.9}loc')]
        check(bool(locs), 'Empty sitemap index')
        for loc in locs:
            with urllib.request.urlopen(loc, timeout=15) as response:
                check(response.status == 200, 'Advertised subsitemap is inaccessible')
                ET.fromstring(response.read())
        path = '/inklura-sitemap-content-999999.xml' if structure else '/?inklura_seo_sitemap=1&inklura_seo_sm_type=content&inklura_seo_sm_page=999999'
        check(request(path)[0] == 404, 'Out-of-range sitemap did not return HTTP 404')
        check(request('/?inklura_seo_sitemap=1&inklura_seo_sm_type[]=content')[0] == 404, 'Malformed sitemap type did not return HTTP 404')
        body = request('/?p='+post)[2]
        check(body.count('rel="canonical"') == 1, 'Duplicate or missing canonical tag over HTTP')
        check('<title>HTTP SEO custom title</title>' in body, 'Custom document title missing over HTTP')
        check('property="og:title"' in body and 'application/ld+json' in body, 'Social/schema output missing')
        check('critical error' not in body.lower(), 'PHP fatal error while rendering long UTF-8 content')
    settings(enable_titles=0)
    body = request('/?p='+post)[2]
    check('name="description"' not in body, 'Disabled title module still emits descriptions')
    check('property="og:description"' in body, 'Disabling titles broke social descriptions')
    check('application/ld+json' in body and 'été' in body, 'Disabling titles broke schema descriptions')
    check(body.count('rel="canonical"') == 1, 'Core canonical was removed with title module disabled')
    settings(enable_breadcrumbs=0)
    helper = wp('eval', "echo function_exists('Inklura\\SEO\\inklura_seo_breadcrumbs') ? 'exists:' . Inklura\\SEO\\inklura_seo_breadcrumbs(array('echo'=>false)) : 'missing';")
    check(helper == 'exists:', 'Disabled breadcrumbs left an unavailable or active template helper')
    settings(enable_sitemap=0)
    check(request('/inklura-sitemap.xml')[0] == 404, 'Disabled sitemap still accessible')
    check(request('/?inklura_seo_sitemap=1')[0] == 404, 'Disabled query-string sitemap still accessible')
    check('inklura-sitemap' not in request('/robots.txt')[2], 'Disabled sitemap advertised in robots.txt')
    settings()
    wp('option', 'update', 'blog_public', '0')
    check(request('/inklura-sitemap.xml')[0] == 404, 'Nonpublic sitemap still accessible')
    check('inklura-sitemap' not in request('/robots.txt')[2], 'Nonpublic sitemap advertised in robots.txt')
    wp('option', 'update', 'blog_public', '1')
    wp('plugin', 'deactivate', 'inklura-seo')
    rules = wp('option', 'get', 'rewrite_rules', '--format=json')
    check('inklura-sitemap' not in rules, 'Deactivation left obsolete sitemap rewrite rules')
    wp('plugin', 'activate', 'inklura-seo')
    check(request('/inklura-sitemap.xml')[0] == 200, 'Reactivation did not restore the sitemap')
    print(f'PASS: {checks} real HTTP, permalink and module-toggle checks')
finally:
    if post:
        wp('post', 'delete', post, '--force')
    for key, value in original.items():
        wp('option', 'update', key, value, '--format=json')
    wp('rewrite', 'flush')
