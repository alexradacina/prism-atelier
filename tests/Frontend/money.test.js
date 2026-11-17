import test from 'node:test';
import assert from 'node:assert/strict';
import { money, toNanos } from '../../resources/js/lib.js';
test('statement amounts retain every nanodollar beyond floating-point safe precision', () => {
    assert.equal(money('9007199.254740993',true),'$9,007,199.254740993');
    assert.equal(toNanos('9007199.254740993'),9007199254740993n);
    assert.equal(money('0.000000001',true),'$0.000000001');
});
test('short prices round for display while statements remain exact', () => {
    assert.equal(money('0.123456499'),'$0.123456');
    assert.equal(money('0.123456500'),'$0.123457');
    assert.equal(money('0.999999999'),'$1.00');
    assert.equal(money('-0.018734375',true),'−$0.018734375');
    assert.equal(money(null),'—');
});
test('affordability comparison distinguishes a single nanodollar', () => {
    assert.equal(toNanos('0.041481249') >= toNanos('0.041481250'),false);
    assert.equal(toNanos('0.041481250') >= toNanos('0.041481250'),true);
});
