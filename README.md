# SyncLDAPUsers
WordPress plugin to sync LDAP user accounts.

Queries LDAP for records modified and proceses them in chronological order.

Install like any plugin...  Network-enable, then supply host/credentials

Very likely will not work "out-of-the-box" at your institution (at this point)

Assumes:
* your LDAP uses modifyTimeStamp attribute
* requests data in 2 week ranges - if you have a very large directory, or lots of updates, you might run into max_execution_time issues.
