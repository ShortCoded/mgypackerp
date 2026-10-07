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
      const gross = line.booked_gross != null ? value(line.booked_gross) : (n.multiply(value(line.quantity), value(line.unit_price), 4) || '0');
      const own = line.booked_own_discount != null ? value(line.booked_own_discount) : amount(gross, line.discount_type || 'fixed', line.discount_value ?? line.discount_amount ?? '0');
      valid = valid && validInput(gross, line.discount_type || 'fixed', line.discount_value ?? line.discount_amount ?? '0');
      subtotal = n.add(subtotal, gross); ownTotal = n.add(ownTotal, own);
      return { gross, own_discount_amount: own, base: n.subtract(gross, own), booked_header_discount: line.booked_header_discount, tax_amount: value(line.tax_amount), tax_rate: line.tax_rate, tax_calculation_basis: line.tax_calculation_basis ?? (line.tax_rate != null && line.tax_rate !== '' ? 'rate' : 'legacy_amount') };
    });
    const base = n.subtract(subtotal, ownTotal);
    const header = rows.length > 0 && rows.every(row => row.booked_header_discount != null)
      ? rows.reduce((sum, row) => n.add(sum, value(row.booked_header_discount)), '0') : amount(base, headerType, headerValue);
    valid = valid && validInput(base, headerType, headerValue);
    let allocated = '0'; let remainingBase = base; const positive = rows.map((row, i) => n.compare(row.base, '0') > 0 ? i : -1).filter(i => i >= 0);
    const last = positive.at(-1);
    rows.forEach((row, i) => {
      let share = n.compare(header, '0') === 0 || n.compare(base, '0') <= 0 ? '0'
        : i === last ? n.subtract(header, allocated) : n.divide(n.multiply(header, row.base), base, 4);
      remainingBase = n.subtract(remainingBase,row.base);
      const minimum = n.subtract(n.subtract(header,allocated),remainingBase);
      if (n.compare(share,minimum) < 0) share = minimum;
      if (n.compare(share,row.base) > 0) share = row.base;
      if (row.booked_header_discount != null) share = value(row.booked_header_discount);
      allocated = n.add(allocated, share);
      row.header_discount_amount = share;
      row.discount_amount = n.add(row.own_discount_amount, share);
      if (row.tax_calculation_basis === 'rate') {
        valid = valid && /^[0-9]{1,3}(?:\.[0-9]{1,4})?$/.test(String(row.tax_rate ?? '')) && n.compare(value(row.tax_rate), '100') <= 0;
        row.tax_amount = n.round(n.divide(n.multiply(n.subtract(row.gross, row.discount_amount), value(row.tax_rate)), '100', 12), 4);
      }
      tax = n.add(tax, row.tax_amount);
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
