#!/usr/bin/env python
"""
Copyright (C) 2012 Legoktm

Permission is hereby granted, free of charge, to any person obtaining
a copy of this software and associated documentation files (the "Software"),
to deal in the Software without restriction, including without limitation
the rights to use, copy, modify, merge, publish, distribute, sublicense,
and/or sell copies of the Software, and to permit persons to whom the
Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS
OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
IN THE SOFTWARE.
"""

import requests

class SSMWError(Exception):
    """Any error"""

class Wiki:
    def __init__(self, api, headers=None):
        self.api = api
        self.cookies = None
        self.username = None
        if headers:
            self.headers = headers
        else:
            self.headers = {'User-agent':'supersimplemediawiki by en:User:Legoktm'}

    def login(self, username, passw):
        """
        Logs the user in.
        @param username Account's username
        @type username str
        @param passw Account's password
        @type passw str
        """
        self.username = username
        data = {'action':'login',
                'lgname':username,
                'lgpassword':passw,
                'format':'json'
        }
        r1 = requests.post(self.api, params=data, headers=self.headers)
        if not r1.ok:
            raise SSMWError(r1.text)
        if not r1.json():
            raise SSMWError(r1.text)
        token = r1.json()['login']['token']
        data['lgtoken'] = token
        r2 = requests.post(self.api, params=data, headers=self.headers, cookies=r1.cookies)
        if not r2.ok:
            raise SSMWError(r2.text)
        self.cookies = r2.cookies

    def request(self, params, post=False, raw=False):
        """
        Makes an API request with the given params.
        Returns the page in a dict format
        """
        params['format'] = 'json' #force json
        r = self.fetch(self.api, params, post=post)
        if raw:
            return r
        if not r.json():
            raise SSMWError(r.text)
        return r.json()

    def fetch(self, url, params=None, post=False):
        if post:
            r = requests.post(url, params=params, cookies=self.cookies, headers=self.headers)
        else:
            r = requests.get(url, params=params, cookies=self.cookies, headers=self.headers)
        if not r.ok:
            raise SSMWError(r.text)
        return r
