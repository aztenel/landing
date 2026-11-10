-- The public site under its own role (TZ 05 §1 «правило изоляции»). Run as postgres in the prime database.
-- Then in .env: DB_SITE_USERNAME=prime_site, DB_SITE_PASSWORD=<password from the password manager, not in git>.
-- Even a mistake in a site template cannot read the warehouse cost, suppliers or customers.
CREATE ROLE prime_site LOGIN PASSWORD 'CHANGE_ME_ON_THE_SERVER';
GRANT CONNECT ON DATABASE prime TO prime_site;
GRANT USAGE ON SCHEMA public TO prime_site;
-- read: the safe copy for the site and what its pages need
GRANT SELECT ON storefront_listings, storefront_accessories, storefront_stores, storefront_pages, storefront_banners,
                organizations, receipt_links TO prime_site;
-- the electronic receipt /r/<token> shows one sale: the rows of that sale only (no customers, no cost columns are rendered)
GRANT SELECT ON documents, sales, sale_lines, sale_payments, stock_units, variants, models, memories, colors, accessories, stores TO prime_site;
-- write: anonymous requests, counters, sessions
GRANT SELECT, INSERT, UPDATE ON site_leads, site_stats, sessions TO prime_site;
GRANT USAGE, SELECT ON SEQUENCE site_leads_id_seq, site_stats_id_seq TO prime_site;
-- never: customers, suppliers, debts, money, cost tables, journals
REVOKE ALL ON customers, suppliers, debt_entries, money_entries, counterparty_balances, inventory_movements, purchases, purchase_lines FROM prime_site;
