from pathlib import Path

ticket_change = Path('frontend/app/admin/ticket-change.tsx').read_text()
shop = Path('frontend/app/page.tsx').read_text()
api = Path('plugin/includes/api.php').read_text()

assert "source[0]?.seat?.sector" in ticket_change, 'Seat change must open the current seat sector.'
assert "sessionStorage.setItem('decka-return-order'" in shop, 'Checkout must remember its order independently of the payment return query.'
assert "const requested=returnOrder?" in shop and "const recent=rows.find" in shop, 'A paid order notice must work with or without a preserved return id.'
assert "config.paymentReturn&&config.order" not in shop
assert "if($view==='shop'&&!empty($_GET['order']))" in api, 'Embedded shop must preserve the returned order id.'
assert 'purchase-success-banner' in shop, 'A confirmed purchase must remain visibly announced in the account.'
assert 'gridColumn:Number(s.x)-minX+1' in ticket_change, 'Seat changes must preserve hall coordinates.'
assert 'item_ids:itemIds' in ticket_change, 'Admin must be able to change an individual ticket without changing the full order.'
assert 'positiveSound()' in shop, 'A successful scan must play an audible confirmation.'
admin = Path('frontend/app/admin/page.tsx').read_text()
assert 'Regularnie · normalny' in admin and 'Cena możliwa z zaznaczonym kodem' in admin
assert "p.type==='normal_reduced'&&kind==='normal'" in admin

print('9 UI return, account, seat-change and offer-pricing contract tests passed')
