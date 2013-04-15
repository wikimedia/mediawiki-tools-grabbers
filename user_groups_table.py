#!/usr/bin/env python

# Released into the public domain
# By Legoktm & Uncyclopedia development team, 2013

import oursql
import settings
import mw
uncy = mw.Wiki(settings.api_url)


def gen():
    go_on = True
    #action=query&list=allusers&auprop=groups&format=jsonfm
    params = {'action':'query',
              'list':'allusers',
              'aulimit':'max',
              'auprop':'groups',
              'augroup':'autopatrolled|bot|bureaucrat|rollback|sysop'
              }
    while go_on:
        req=uncy.request(params)
        print 'fetched'
#        print type(req['query']['blocks'])
        yield req['query']['allusers']
        if req.has_key('query-continue'):
            params['aufrom'] = req['query-continue']['allusers']['aufrom']
            print params['aufrom']
        else:
            print 'nomorecontinue'
            go_on = False


def insert():
    print 'Populating the user_groups table...'
    conn = oursql.connect(host=settings.db_host, user=settings.db_pass, passwd=settings.db_pass,
                          db=settings.db_name)
    cur = conn.cursor()
    cur.executemany('INSERT IGNORE INTO `user_groups` VALUES (?,?);', parse(gen()))
    conn.close()


def parse(users):
    for userset in users:
        for user in userset:
            for group in user['groups']:
                if not (group in ('*', 'user')):
                    thing = int(user['userid']), group
                    print thing
                    yield thing



def convert_ts(i):
    #2013-01-05T01:16:52Z
    output = i.replace('-','').replace('T','').replace(':','').replace('Z','')
    return output

if __name__ == "__main__":
    insert()
