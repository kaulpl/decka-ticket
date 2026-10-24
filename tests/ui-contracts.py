from pathlib import Path

ticket_change = Path('frontend/app/admin/ticket-change.tsx').read_text()
shop = Path('frontend/app/page.tsx').read_text()
api = Path('plugin/includes/api.php').read_text()

assert "source[0]?.seat?.sector" in ticket_change, 'Seat change must open the current seat sector.'
assert "if(config.order&&!shown)" in shop, 'A paid order return must not depend on an optional success flag.'
assert "config.paymentReturn&&config.order" not in shop
assert "if($view==='shop'&&!empty($_GET['order']))" in api, 'Embedded shop must preserve the returned order id.'

print('4 UI return and seat-change contract tests passed')
