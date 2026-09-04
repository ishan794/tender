# Sri Lanka Identity & Business Registration Validation Specification

**Document Version**: 1.0  
**Target Platform**: TenderHub (Rev 3.0 Production Hardening)  
**Scope**: NIC (National Identity Card) & BRN (Business Registration Number / Company Reg No)

---

## 1. National Identity Card (NIC)

Sri Lanka currently operates with two official National Identity Card formats issued by the Department of Registration of Persons:

### A. Legacy Format (9-digit + Letter)
- **Structure**: 9 numeric digits followed by a single letter:
  - 2 digits for birth year (e.g. `85` for 1985).
  - 3 digits for day of year (+500 for female citizens).
  - 3 sequential digits.
  - 1 check digit.
  - 1 suffix letter: `V` (eligible voter) or `X` (non-voter / special cases). Case-insensitive in input.
- **Pattern**: `^[0-9]{9}[vVxX]$`
- **Example**: `851234567V`, `927890123X`

### B. Modern Format (12-digit)
- **Structure**: 12 numeric digits introduced in 2016:
  - 4 digits for 4-digit birth year (e.g. `1985`).
  - 3 digits for day of year (+500 for female citizens).
  - 4 sequential digits.
  - 1 check digit.
- **Pattern**: `^[0-9]{12}$`
- **Example**: `198512345678`, `200178901234`

### Validation Rule
```regex
/^(?:[0-9]{9}[vVxX]|[0-9]{12})$/
```

---

## 2. Business Registration Number (BRN / Reg No)

In Sri Lanka, business registrations fall into two primary jurisdictions:

### A. Companies Incorporated under Companies Act No. 7 of 2007 (Central ROC)
- Handled centrally by the Department of the Registrar of Companies (ROC) / e-ROC system.
- Standard corporate prefixes:
  - `PV`: Private Limited Companies (e.g. `PV 12345`, `PV00234567`, `PV-102934`)
  - `PB`: Public Limited Companies
  - `GA`: Companies Limited by Guarantee
  - `FC`: Foreign Companies
  - `W`: Legacy Western Province company records
- Modern e-ROC assigns numbers like `PV00123456`.

### B. Sole Proprietorships & Partnerships (Provincial Secretariats)
- Registered under Provincial Business Names Statutes across the 9 Provinces.
- Issued by Divisional Secretariats with varying localized prefixes and format notations (e.g. `W/DS/CO/2021/104`, `CP/KD/BN/1029`, or regional numeric records).

### Validation Rule & Documented Limitation
Because of divergent formatting between central corporate e-ROC registers and the 9 provincial business name registries, TenderHub implements the following strict baseline validation:
1. **Length**: 3 to 60 characters (`VARCHAR(60)` in database).
2. **Allowed Characters**: Alphanumeric characters, forward slashes (`/`), hyphens (`-`), and spaces.
3. **Pattern**: `/^[a-zA-Z0-9\/\-\s]{3,60}$/`
4. **Placeholder Rejection**: Disallows non-identifying placeholders such as `N/A`, `none`, `test`, `00000`.
5. **Uniqueness**: When supplied, the normalized `reg_no` must be unique across active organisations to prevent corporate impersonation.
