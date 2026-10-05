(function (root) {
  'use strict';
  function calculate(lines, headerType, headerValue) {
    const n = root.AppNumbers;
    const value = input => n.normalize(String(input ?? '0')) || '0';
    const amount = (base, type, input) => type === 'percentage'
      ? n.round(n.divide(n.multiply(base, value(input)), '100', 12), 4)
      : type === 'fixed' ? value(input) : '0';
    const validInput = (base, type, input) => /^[0-9]{1,16}(?:\.[0-9]{1,4})?$/.test(String(input ?? '0'))
      && [null, '', 'fixed', 'percentage'].includes(type)
      && (type || n.compare(value(input), '0') === 0)
      && (type !== 'percentage' || n.compare(value(input), '100') <= 0)
      && n.compare(amount(base, type, input), base) <= 0;
    let subtotal = '0'; let ownTotal = '0'; let tax = '0'; let valid = true;
    const rows = lines.map(line => {
      const gross = n.multiply(value(line.quantity), value(line.unit_price), 4) || '0';
      const own = amount(gross, line.discount_type || 'fixed', line.discount_value ?? line.discount_amount ?? '0');
      valid = valid && validInput(gross, line.discount_type || 'fixed', line.discount_value ?? line.discount_amount ?? '0');
      subtotal = n.add(subtotal, gross); ownTotal = n.add(ownTotal, own); tax = n.add(tax, value(line.tax_amount));
      return { gross, own_discount_amount: own, base: n.subtract(gross, own), tax_amount: value(line.tax_amount) };
    });
    const base = n.subtract(subtotal, ownTotal);
    const header = amount(base, headerType, headerValue);
    valid = valid && validInput(base, headerType, headerValue);
    let allocated = '0'; const positive = rows.map((row, i) => n.compare(row.base, '0') > 0 ? i : -1).filter(i => i >= 0);
    const last = positive.at(-1);
    rows.forEach((row, i) => {
      const share = n.compare(header, '0') === 0 || n.compare(base, '0') <= 0 ? '0'
        : i === last ? n.subtract(header, allocated) : n.divide(n.multiply(header, row.base), base, 4);
      allocated = n.add(allocated, share);
      row.header_discount_amount = share;
      row.discount_amount = n.add(row.own_discount_amount, share);
      row.line_total = n.add(n.subtract(row.gross, row.discount_amount), row.tax_amount);
    });
    const discount = n.add(ownTotal, header);
    return { valid, lines: rows, subtotal_amount: subtotal, discount_amount: discount, header_discount_amount: header,
      tax_amount: tax, total_amount: n.add(n.subtract(subtotal, discount), tax) };
  }
  function withholding(grossIncludingTax, rate, basis = 'gross_including_tax', netExcludingTax = grossIncludingTax) {
    const n = root.AppNumbers;
    const normalizedRate = n.normalize(String(rate ?? '0')) || '0';
    const basisAmount = basis === 'eta_t4_net_excluding_tax' ? netExcludingTax : grossIncludingTax;
    const amount = n.round(n.divide(n.multiply(basisAmount, normalizedRate), '100', 12), 4);
    return { withholding_basis_amount: basisAmount, withholding_amount: amount, net_payable_amount: n.subtract(grossIncludingTax, amount) };
  }
  root.AppSalesDiscounts = { calculate, withholding };
})(window);
