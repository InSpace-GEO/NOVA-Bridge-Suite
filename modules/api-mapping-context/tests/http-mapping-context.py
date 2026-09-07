"""Authenticated HTTP regression for a post with saved builder field instructions.
Set NOVA_TEST_URL, NOVA_TEST_POST_ID, NOVA_TEST_COOKIE and NOVA_TEST_NONCE.
Credentials stay in memory; redirects are refused. No site writes.
"""
import json
import os
import urllib.request

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None

def check(base, post_id, cookie, nonce):
    opener = urllib.request.build_opener(NoRedirect)
    def read(context, authenticated):
        headers = {'Cookie': cookie, 'X-WP-Nonce': nonce} if authenticated else {}
        request = urllib.request.Request(base.rstrip('/') + '/wp-json/wp/v2/posts/' + str(int(post_id)) + '?context=' + context, headers=headers)
        with opener.open(request, timeout=30) as response:
            return json.load(response)
    edit = read('edit', True)
    mappings = edit.get('nova_content_mappings', {})
    builders = {key: value for key, value in mappings.items() if key.startswith('/@builders/')}
    instructions = edit.get('meta_descriptions', {})
    assert builders, 'Authenticated edit response lost builder mappings'
    assert any(instructions.get(key) for key in builders), 'Builder instructions are missing'
    for authenticated in (False, True):
        view = read('view', authenticated)
        assert 'nova_strategy_context' not in view, 'Private layout context leaked into view response'
        assert not any(key in (view.get('nova_content_mappings') or {}) for key in builders), 'Private builder mappings leaked into view response'
    return {'builder_mappings': builders, 'instructions': {key: instructions[key] for key in builders if key in instructions}}

if __name__ == '__main__':
    result = check(os.environ['NOVA_TEST_URL'], os.environ['NOVA_TEST_POST_ID'], os.environ['NOVA_TEST_COOKIE'], os.environ['NOVA_TEST_NONCE'])
    print('PASS authenticated HTTP builder mappings, instructions and view privacy:', len(result['builder_mappings']), 'builder mappings')
