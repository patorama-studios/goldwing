# AGA Members Data — Pre-Import Audit Questions
**Prepared:** 4 May 2026  
**Source file:** Current AGA Members.xlsx  
**Total member rows:** 158 across 29 chapter name variations

These questions need answers before member data can be cleanly imported into the new website system. They are grouped by topic and ordered roughly by urgency.

---

## Section 1 — Critical Data Errors (must fix before import)

**Q1. Two members appear expired but are still in the active list — are these errors?**

- **#1471 Lorenzo (Laurie) Caruso** — expiry date is **31 July 2023** (nearly 3 years ago)
- **#1472 Alan Paul** — expiry date is **31 July 2015** (over 10 years ago)

*Are these members still active and the expiry date is wrong, or should they be marked as lapsed/inactive and excluded from the import?*

---

**Q2. Member #949 Graham Merrick has a renewal date of the year 2999 — this is clearly a data entry error.**

His record shows:
- Joined: 1 April 1998
- Renewed: **31 July 2999** (typo — should probably be 1999 or a recent year)
- Expiry: 31 July 2099 (life member style date, but he is NOT ticked as Life Member)
- He is also marked as **Historic**

*Is Graham Merrick a Life Member? And what should the correct renewal date be?*

---

**Q3. Member #949 aside — one other member has a 2099 expiry but is NOT marked as a Life Member.**

Same member as above. The system treats 2099-style expiry dates as "Life Member." If he isn't officially a Life Member, his expiry date needs correcting.

---

## Section 2 — Chapter & Geography Questions

**Q4. Is "Adelaide Chapter" the same organisation as "South Australian Chapter"?**

The spreadsheet contains:
- **"Adelaide Chapter"** — 15 members
- **"South Australian Chapter" / "South Australia" / "South Australian"** — 4 members (clearly the same, just entered differently)

*Are these the same chapter? Or is Adelaide Chapter a separate city-based chapter distinct from the broader South Australian Chapter?* This affects whether 15 or 19 members get loaded under one chapter.

---

**Q5. Four chapter names in the spreadsheet do not exist in the current database — are they official AGA chapters that need to be created?**

| Chapter Name | Members |
|---|---|
| ACT & Southern Tablelands Chapter | 3 |
| Holiday Coast Chapter | 4 |
| Southern Districts Chapter | 2 |
| South Coast NSW Chapter | 2 |

*Are these active, officially recognised AGA chapters? If yes, they need to be added to the system before those members can be imported.*

---

**Q6. Ten members have an address in a different state to their chapter — is this intentional?**

Members can be in any chapter regardless of where they live (e.g. they may have joined in a different city and never transferred), but we want to confirm rather than assume:

| Member | Lives In | Chapter |
|---|---|---|
| #17 Larry Martin | Wingham NSW | Brisbane Chapter |
| #694 Bruce Dugan | Tirrannaville NSW | ACT & Southern Tablelands |
| #795 David Partridge | Kariong WA | Central Coast Chapter (NSW) |
| #863 Ron Leslie | Ainslie ACT | Riverina Chapter (NSW) |
| #1240 Stewart Winnie | Richardson ACT | Riverina Chapter (NSW) |
| #1436 Brian Parnell | Blackwater QLD | Sydney Chapter |
| #1560 Matthew Kari | West Wollongong NSW | ACT & Southern Tablelands |
| #1566 Michael Nuyttens | Helensvale QLD | Holiday Coast Chapter (NSW) |
| #1670 Morgan Dunbar | Gordon ACT | Riverina Chapter (NSW) |
| #1690 Don Rupp | Bright VIC | Riverina Chapter (NSW) |

*Are all of these intentional chapter memberships? Any that should be transferred to their local chapter?*

---

**Q7. Four members have no chapter assigned at all.**

Two are listed as the literal text "(None)", one as "No Chapter available", and one as "Honorary Member":

- **#1481** (member in the list — no chapter)
- **#1546** Kev Lane (no chapter)
- **#1686** (No Chapter available)
- **#1099 Greg Snart** (Honorary Member)

*What chapter or status should these members be assigned?  
Is "Honorary Member" a formal membership type that needs to exist in the system?*

---

## Section 3 — Associate Member Questions

**Q8. Twelve members have associate join/renewal dates recorded but NO associate name. Who are these associates?**

| Main Member | Assoc Joined | Assoc Renewed | Assoc Historic |
|---|---|---|---|
| #295 Wendy Woodward | 1/10/1990 | — | No |
| #731 Robert Thiel | 1/06/1995 | 7/07/2015 | No |
| #836 Rowland Wayman | 1/11/1996 | 23/07/2021 | No |
| #863 Ron Leslie | 25/05/2001 | 13/07/2016 | **Yes** |
| #1076 Graham Wasley | 1/06/2009 | 25/06/2015 | No |
| #1099 Greg Snart | 8/04/2002 | — | No |
| #1191 Barb Rivett | 8/02/2005 | 31/07/2014 | No |
| #1352 Martin Woodward | 27/03/2018 | 31/08/2021 | **Yes** |
| #1516 Douglas Lamb | 31/07/2020 | 4/09/2023 | No |
| #1584 Phil Vincent | 26/08/2023 | 26/08/2023 | No |
| #1655 Helen McKenzie | 1/12/2022 | 5/06/2025 | No |
| #1672 Stephane (Steve) Recoquillion | 28/09/2023 | — | No |

*Did these associates leave, pass away, or separate from the main member? Should their historical dates be kept for records or removed? The system needs a name to create an associate account.*

---

**Q9. Five associates are flagged as "Historic" but have no name recorded — what should happen to this data?**

Members #863, #1352, #1601, #1687, and #1698 all have an associate flagged as Historic with dates, but no name attached.

*Should these Historic flags be removed since there's no named person, or is the historical membership record important to preserve?*

---

**Q10. Policy question: Can an Associate Member hold a position on the National Committee?**

Two associate members are currently flagged as NatComm in the spreadsheet:

- **#1274** — Associate: Robyn Furner (main member Lewis William Furner is also NatComm)
- **#1559** — Associate: Elizabeth MacPherson (main member Peter Andrew Macpherson is also NatComm)

*Is it valid for an associate to hold a NatComm position? If so, the system needs to track this against their individual associate record.*

---

## Section 4 — Membership Type & Status Questions

**Q11. What does "Historic" membership actually mean in AGA?**

Eight "Historic" members currently have active/future memberships (not expired). This means "Historic" is not the same as inactive or lapsed. Possibilities:

- They ride a **historic/classic bike** and this is a vehicle classification
- They are a **founding/long-serving member** with a special honorary status
- It is a **separate membership category** with different fees or entitlements

*What is the correct definition? This affects how the field is labelled and used in the new system.*

---

**Q12. Should "Honorary Member" be a formal membership type in the new system?**

Member #1099 Greg Snart is classified as "Honorary Member" in the chapter field (not a membership type). He is also a Life Member.

*Is Honorary Member a distinct membership category with different rights, or is it just a description applied informally? Should it be a proper field in the system?*

---

## Section 5 — Contact Information & Login Access

**Q13. The new system requires a unique email address per person. 36 couples currently share one email address.**

This is common in older database systems. In the new website, each person (main member and associate) needs their own email address to:
- Log in to the member portal
- Receive their own renewal reminders
- Access their own account

Examples of shared emails:
- #7 Malcolm & Helen Pryor share `malnhelen1@bigpond.com`
- #27 Gary & Yvonne Jones share `gjo51914@bigpond.net.au`
- ... (34 other couples)

*How do you want to handle this? Options:*
1. *Associates get their own login — require individual emails before import*
2. *Associates share the main member's login — only one portal account per household*
3. *Associates are imported without a login account (view-only or no portal access)*

---

**Q14. Five main members have no email address recorded.**

- **#148 Kristopher Farrell**
- **#343 Frank Milligan**
- **#694 Bruce Dugan**
- **#950 Peter Brannan**
- **#1613 Stephen Graham Wicks**

*Can these be obtained before import? Without an email, these members cannot log in to the member portal or receive digital communications.*

---

**Q15. Eight members have no phone number recorded.**

Members: #585 Trevor Langley (OAM), #942 Peter Cherry, #950 Peter Brannan, #1531 Graeme Dippel, #1566 Michael Nuyttens, #1606 Alan Vesperman, #1609 Tony Collins, #1687 Chris Cox.

*Is this acceptable or should these be chased up before import?*

---

## Section 6 — Roles & Positions

**Q16. Two members are flagged as BOTH Area Rep AND NatComm simultaneously — is this correct?**

- **#1514 Wayne Gannon**
- **#1596 David Robin Goodchild**

*Can a person hold both positions at the same time, or should one take precedence? The system will store both flags — just confirming this is intentional.*

---

**Q17. Should "NatComm" (National Committee) and "Area Rep" be stored as tracked member roles in the new system?**

Currently these are yes/no flags in the spreadsheet. The new system can either:
- Keep them as simple flags on the member record (yes/no), or
- Track them as formal named positions with a start/end date

*Do NatComm and Area Rep positions have terms or end dates that should be recorded?*

---

## Section 7 — System & Process Questions

**Q18. What is the correct membership year cycle?**

All expiry dates in the spreadsheet end on **31 July** of a given year. This suggests the AGA membership year runs from 1 August to 31 July.

*Is this correct? This needs to be confirmed so the system calculates renewal windows and reminders accurately.*

---

**Q19. Should members with multiple paid-up years (expiry 2027, 2028) show as "active until" that year, or is that unusual?**

Several members have expiry dates 2–3 years in the future, suggesting they may have paid multiple years in advance. For example:
- #7 Malcolm Pryor — expiry 2099 (Life)
- #790 Alan Springett — expiry 2028
- #863 Ron Leslie — expiry 2028

*Is multi-year membership renewal supported? Or should expiry always be the next 31 July?*

---

**Q20. What happens to associate membership when the main member's membership lapses?**

The new system links associates to their main member. If the main member does not renew, does:
- The associate automatically lapse too?
- The associate need to renew independently?
- The associate get a separate renewal notice?

*This needs a policy decision to program the renewal logic correctly.*

---

*End of audit questions — 20 items total.*  
*Once these are answered, the chapter-by-chapter import can proceed in a controlled way.*
